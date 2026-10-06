<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\ComposeFile;
use PetarSpasic\LaravelHouse\Kanban\Code\Dependencies;
use PetarSpasic\LaravelHouse\Kanban\Code\PortRegistry;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Code\StackUser;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Migrate;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\NextSteps;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\OldBoard;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\DeployKey;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\SyncStatus;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\DiskCheck;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Sync;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Gh;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'kanban:doctor')]
class DoctorCommand extends Command
{
    /** Docker's built-in pools, used when `docker info` reports none. */
    private const DOCKER_DEFAULT_POOLS = [
        ['Base' => '172.17.0.0/16', 'Size' => 16], ['Base' => '172.18.0.0/16', 'Size' => 16], ['Base' => '172.19.0.0/16', 'Size' => 16],
        ['Base' => '172.20.0.0/14', 'Size' => 16], ['Base' => '172.24.0.0/14', 'Size' => 16], ['Base' => '172.28.0.0/14', 'Size' => 16],
        ['Base' => '192.168.0.0/16', 'Size' => 20],
    ];

    protected $signature = 'kanban:doctor {--fix : Re-run attach and the safe install steps first (main session or owner)}';

    protected $description = 'Check the board, git wiring, Claude Code hooks, agents, runtime, worktrees and the stack setup';

    /** @var list<array{0: string, 1: string}> */
    private array $results = [];

    protected function perform(): int
    {
        if ($this->option('fix')) {
            $this->requireMainOrOwner('doctor --fix');
            $this->fix();
        }
        array_push($this->results, ...(new Migrate($this->paths(), $this->config()))->check());
        $this->checkGit();
        $bootstrap = new Bootstrap($this->paths(), $this->config());
        foreach (Steps::make($this->paths(), $this->config()) as $step) {
            array_push($this->results, ...$step->check());
        }
        $this->checkRuntime();
        $this->checkDisks();
        if ($bootstrap->attached()) {
            $this->checkSync();
            if (($snapshot = $this->checkBoard()) !== null) {
                $this->checkOrphans($snapshot);
            }
        }
        $this->checkStacks();
        foreach (Dependencies::problems($this->paths()->main, (array) ($this->setting('worktrees.copy') ?? [])) as $problem) {
            $this->add('warn', $problem);
        }
        $this->checkUpstream();

        foreach ($this->results as [$level, $text]) {
            $this->say("{$level} {$text}");
        }
        if ($this->option('fix')) {
            $this->say('next: '.NextSteps::RESTART);
        }

        return in_array('fail', array_column($this->results, 0), true) ? 1 : self::SUCCESS;
    }

    private function fix(): void
    {
        $migrate = new Migrate($this->paths(), $this->config());
        foreach ($migrate->run() as $line) {
            $this->say("fix: {$line}");
        }
        if ($migrate->blocked()) {
            $this->say('fix: '.'stopped: '.Migrate::RUNTIME.' is still there (see above); attach waits for it, so no second deploy key is made');
        } else {
            try {
                foreach ((new Bootstrap($this->paths(), $this->config()))->attach() as $line) {
                    $this->say(str_starts_with($line, 'ssh-') ? $line : "fix: {$line}");
                }
            } catch (Throwable $e) {
                $this->say('fix: attach failed: '.$e->getMessage());
            }
        }
        foreach (Steps::make($this->paths(), $this->config()) as $step) {
            foreach ($step->run() as $line) {
                $this->say("fix: {$line}");
            }
        }
        if (($user = $this->stackUser()) !== null && ($line = $user->fix()) !== null) {
            $this->say("fix: {$line}");
        }
    }

    private function stackUser(): ?StackUser
    {
        $stack = (array) $this->setting('stack', []);

        return Stack::enabled($stack, $this->paths()->main) ? new StackUser($this->paths()->main, (string) $stack['compose_file']) : null;
    }

    private function add(string $level, string $text): void
    {
        $this->results[] = [$level, $text];
    }

    private function checkGit(): void
    {
        $paths = $this->paths();
        if (! is_dir($paths->gitDir())) {
            $this->add('fail', "no main checkout found from {$paths->cwd}");

            return;
        }
        $git = new Git($paths->main);
        $attached = (new Bootstrap($paths, $this->config()))->attached();
        $this->add($attached ? 'ok' : 'fail', $attached ? 'board attached at docs/kanban' : 'board not attached at docs/kanban (run `vendor/bin/kanban attach`)');

        $driver = (string) $git->line(['config', '--get', 'merge.kanban.driver']);
        $script = preg_match("/^php\s+((?:'[^']*'|\\\\.|[^\s'\\\\])+)\s+merge-driver\b/", $driver, $m) ? self::shellWord($m[1]) : null;
        $this->add(...match (true) {
            $driver === '' => ['fail', 'merge driver not configured (run `vendor/bin/kanban attach`)'],
            $script === null || ! is_file($script) => ['fail', "merge driver points at a missing script: {$driver} (run `vendor/bin/kanban attach`)"],
            default => ['ok', "merge driver {$script}"],
        });

        $hooksPath = (string) $git->line(['config', '--get', 'core.hooksPath']);
        $unexecutable = array_filter(['commit-msg', 'pre-push'], fn (string $hook) => ! is_executable($paths->main.'/'.Bootstrap::HOOKS_PATH.'/'.$hook));
        $this->add(...match (true) {
            $hooksPath === '' => ['fail', 'core.hooksPath unset: commit-msg and pre-push do not run (run `vendor/bin/kanban attach`)'],
            $hooksPath !== Bootstrap::HOOKS_PATH => ['warn', "core.hooksPath is {$hooksPath}: the kanban commit-msg and pre-push do not run (`vendor/bin/kanban attach --force` switches)"],
            $unexecutable !== [] => ['fail', 'git hooks missing or not executable: '.implode(', ', $unexecutable).' in '.Bootstrap::HOOKS_PATH],
            default => ['ok', 'core.hooksPath '.Bootstrap::HOOKS_PATH],
        });

        $reject = Bootstrap::rejectsCoAuthored($this->config());
        $rejecting = $git->line(['config', '--bool', '--get', Bootstrap::REJECT_CO_AUTHORED]) !== 'false';
        $this->add(...($reject === $rejecting
            ? ['ok', 'commit-msg '.($reject ? 'rejects' : 'allows').' Co-Authored-By trailers']
            : ['warn', 'commit-msg '.($rejecting ? 'rejects' : 'allows').' Co-Authored-By trailers, githooks.reject_co_authored says otherwise (run `vendor/bin/kanban attach`)']));
    }

    private function checkRuntime(): void
    {
        $dir = $this->paths()->ensureRuntime();
        $this->add(is_dir($dir) && is_writable($dir) ? 'ok' : 'fail', 'runtime '.$this->paths()->relative($dir).(is_writable($dir) ? ' writable' : ' not writable'));
    }

    /** Local state only: what sync is doing on this machine, no network. */
    private function checkSync(): void
    {
        $store = $this->gitStore();
        if ($store === null) {
            return;
        }
        $repo = $store->repo();
        $this->add('ok', 'sync '.Sync::label($this->config()['sync'] ?? 'off', $repo->hasRemote()));
        if (! $store->syncOn() && $repo->hasRemoteRef()) {
            $this->add('warn', Sync::PUBLISHED_BUT_OFF.' (KANBAN_SYNC=auto, or delete the `sync` line of a published config/kanban.php)');
        }
        if (($line = (new SyncStatus($this->paths()))->line()) !== null) {
            $this->add('warn', $line);
        }
        $key = new DeployKey($this->paths(), $this->config());
        if ($key->wanted()) {
            $this->add($key->exists() ? 'ok' : 'warn', $key->exists()
                ? 'deploy key '.$this->paths()->relative($key->path()).' (the container syncs with it once its public half is a write deploy key)'
                : 'no deploy key for the container sync: `vendor/bin/kanban doctor --fix` makes one');
        } elseif ($key->tokenWanted()) {
            $token = (DotEnv::parse($this->paths()->main.'/.env')['KANBAN_GIT_TOKEN'] ?? '') !== '' || (string) getenv('KANBAN_GIT_TOKEN') !== '';
            $this->add($token ? 'ok' : 'warn', $token
                ? 'https origin: the container syncs with KANBAN_GIT_TOKEN'
                : "https origin: set KANBAN_GIT_TOKEN in .env for the container sync (a fine-grained token with read and write on this repository's contents)"
                    .(($repo = $key->githubRepo()) !== null ? ", or switch to ssh: `git remote set-url origin git@github.com:{$repo}.git`" : ''));
        }
    }

    /** The board in the format this package reads, or null; and the local edits sync set aside. */
    private function checkBoard(): ?Snapshot
    {
        foreach (glob($this->paths()->displaced('*.json')) ?: [] as $file) {
            $this->add('warn', 'edits of a card deleted on the remote, kept by sync: '.$this->paths()->relative($file).' (read it, then delete it)');
        }
        try {
            return $this->store()->snapshot();
        } catch (OldBoard $e) {
            $this->add('fail', $e->getMessage());

            return null;
        }
    }

    private function checkOrphans(Snapshot $snapshot): void
    {
        $paths = $this->paths();
        $orphans = 0;
        foreach (glob($paths->worktrees().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $card = $snapshot->card(strtoupper(basename($dir)));
            if ($card !== null && (! $card->atWork() || ($card->work()['worktree'] ?? null) !== $paths->relative($dir))) {
                $this->add('warn', "orphan worktree {$paths->relative($dir)}: {$card->id()} is {$card->stage()} (`kanban stop` or `git worktree remove`)");
                $orphans++;
            }
        }
        $list = (new Git($paths->main))->attempt(['worktree', 'list', '--porcelain'])->out;
        foreach (preg_split('/\n\n+/', trim($list)) ?: [] as $block) {
            if (preg_match('/^worktree (.+)$/m', $block, $m) && preg_match('/^prunable/m', $block)) {
                $this->add('warn', "worktree {$m[1]} is gone (git worktree prune, or `kanban stack gc`)");
                $orphans++;
            }
        }
        foreach ((new PortRegistry((array) $this->setting('stack', [])))->all() as $entry) {
            if (($entry['repo'] ?? null) === $paths->main && ! is_dir((string) $entry['worktree'])) {
                $this->add('warn', "stack slot {$entry['slot']} ({$entry['project']}): worktree {$entry['worktree']} is gone (`kanban stack gc`)");
                $orphans++;
            }
        }
        if ($orphans === 0) {
            $this->add('ok', 'no orphan worktrees or stack slots');
        }
    }

    private function checkStacks(): void
    {
        $main = $this->paths()->main;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            $xml = @file_get_contents($main.'/'.$file);
            if (is_string($xml) && preg_match_all('/<(?:env|server)\s+name="(DB_HOST|DB_PORT)"/', $xml, $m) > 0) {
                $this->add('warn', "{$file} hardcodes ".implode(', ', array_unique($m[1])).': tests in a worktree would hit the main database; remove them');
            }
        }
        $stack = (array) $this->setting('stack', []);
        $compose = $stack['compose_file'] ?? null;
        if (! Stack::enabled($stack, $main)) {
            $this->add('ok', 'worktree stacks disabled (stack.compose_file '.(is_string($compose) && $compose !== '' ? "{$compose} not found" : 'unset').')');

            return;
        }
        $yaml = (string) file_get_contents($main.'/'.$compose);
        $problems = ComposeFile::problems($yaml, ComposeFile::portVariables(ComposeFile::withHostPorts($stack, $main)));
        foreach ($problems as $problem) {
            $this->add('fail', "{$compose}: {$problem}");
        }
        if ($problems === []) {
            $this->add('ok', "{$compose} is worktree-safe");
        }
        $user = new StackUser($main, $compose);
        if ($user->stack() !== null) {
            $problem = $user->problem();
            $this->add(...($problem === null ? ['ok', "{$compose} runs the app as the checkout's user ".StackUser::caller()] : ['fail', $problem]));
        }
        $overlays = array_diff(array_map('realpath', [...glob($main.'/docker-compose*.y*ml') ?: [], ...glob($main.'/compose*.y*ml') ?: []]), [realpath($main.'/'.$compose)]);
        foreach ($overlays as $overlay) {
            foreach (ComposeFile::externalVolumeProblems((string) file_get_contents($overlay)) as $problem) {
                $this->add('warn', basename($overlay).": {$problem}");
            }
        }
        $env = DotEnv::parse($main.'/.env');
        $this->add(...(($env['COMPOSE_PROJECT_NAME'] ?? '') !== ''
            ? ['ok', "COMPOSE_PROJECT_NAME={$env['COMPOSE_PROJECT_NAME']} in .env"]
            : ['fail', 'COMPOSE_PROJECT_NAME missing from .env: the main stack has no project name (add e.g. COMPOSE_PROJECT_NAME='.basename($main).'-local)']));
        $this->checkAddressPools();
    }

    /** A shell word as git's `sh -c` reads it: single quotes are literal and a backslash escapes the next character. */
    private static function shellWord(string $word): string
    {
        $out = '';
        $quoted = false;
        for ($i = 0, $n = strlen($word); $i < $n; $i++) {
            $char = $word[$i];
            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif ($char === '\\' && ! $quoted && $i + 1 < $n) {
                $out .= $word[++$i];
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    private function checkDisks(): void
    {
        $minFree = (float) ($this->setting('stack.min_free_ratio') ?? 0.10);
        $low = DiskCheck::low(['tmp' => sys_get_temp_dir(), 'checkout' => $this->paths()->main], $minFree);
        foreach ($low as $text) {
            $this->add('warn', "{$text}: agents' scratch copies fill it; free some, or raise the tmpfs size or nr_inodes");
        }
        $low === [] && $this->add('ok', 'disk space and inodes');
    }

    private function checkUpstream(): void
    {
        if (! Findings::enabled($this->config())) {
            return;
        }
        $problem = (new Gh($this->paths()->main))->unusable(Findings::host($this->config()));
        $this->add(...($problem === null
            ? ['ok', 'gh signed in: `kanban upstream file` files findings on '.Findings::repo($this->config())]
            : ['warn', "{$problem}: `kanban upstream file` cannot file findings (KANBAN_UPSTREAM is on)"]));
    }

    private function checkAddressPools(): void
    {
        $info = Stack::docker(['info', '--format', '{{json .DefaultAddressPools}}']);
        $ids = Stack::docker(['network', 'ls', '-q']);
        if ($info === null || $ids === null) {
            $this->add('warn', 'docker not reachable: address pool headroom not checked');

            return;
        }
        $pools = json_decode(trim($info), true);
        $pools = is_array($pools) && $pools !== [] ? $pools : self::DOCKER_DEFAULT_POOLS;
        $taken = [];
        $ids = preg_split('/\s+/', trim($ids)) ?: [];
        if ($ids !== [] && $ids !== ['']) {
            $subnets = Stack::docker(['network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Subnet}} {{end}}', ...$ids]) ?? '';
            $taken = preg_split('/\s+/', trim($subnets)) ?: [];
        }
        $process = new Process(['ip', '-4', '-o', 'addr', 'show']);
        try {
            $process->run();
            preg_match_all('#inet (\d+\.\d+\.\d+\.\d+/\d+)#', $process->getOutput(), $m);
            $taken = [...$taken, ...$m[1]];
        } catch (Throwable) {
        }
        $ranges = array_values(array_filter(array_map(self::range(...), $taken)));
        $free = 0;
        foreach ($pools as $pool) {
            $free += self::freeBlocks((string) ($pool['Base'] ?? ''), (int) ($pool['Size'] ?? 0), $ranges);
        }
        $pools = implode(', ', array_map(fn (array $p) => "{$p['Base']} (/{$p['Size']} networks)", $pools));
        // one network per card stack, up to stack.max_stacks
        $min = (int) $this->setting('stack.max_stacks', 12);
        $this->add(...($free < $min
            ? ['warn', "docker address pools: {$free} free networks (< {$min}, stack.max_stacks) in {$pools}: widen default-address-pools in /etc/docker/daemon.json (README)"]
            : ['ok', "docker address pools: {$free} free networks"]));
    }

    /** @param  list<array{0: int, 1: int}>  $taken */
    private static function freeBlocks(string $base, int $size, array $taken): int
    {
        $range = self::range($base);
        if ($range === null || $size < 1 || $size > 32) {
            return 0;
        }
        $step = 2 ** (32 - $size);
        $count = intdiv($range[1] - $range[0] + 1, $step);
        if ($count > 65536) {
            return $count;
        }
        $free = 0;
        for ($i = 0; $i < $count; $i++) {
            $start = $range[0] + $i * $step;
            $end = $start + $step - 1;
            $overlaps = false;
            foreach ($taken as [$from, $to]) {
                if ($start <= $to && $from <= $end) {
                    $overlaps = true;
                    break;
                }
            }
            $free += $overlaps ? 0 : 1;
        }

        return $free;
    }

    /** @return array{0: int, 1: int}|null first and last address of an IPv4 CIDR */
    private static function range(string $cidr): ?array
    {
        if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', trim($cidr), $m) !== 1 || ($ip = ip2long($m[1])) === false || (int) $m[2] > 32) {
            return null;
        }
        $size = 2 ** (32 - (int) $m[2]);
        $start = $ip - ($ip % $size);

        return [$start, $start + $size - 1];
    }
}
