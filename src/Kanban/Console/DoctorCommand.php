<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\PortRegistry;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\NextSteps;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\DeployKey;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\SyncStatus;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Sync;
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

    private const MIN_FREE_NETWORKS = 6;

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
        $this->checkGit();
        $bootstrap = new Bootstrap($this->paths(), $this->config());
        foreach (Steps::make($this->paths(), $this->config()) as $step) {
            array_push($this->results, ...$step->check());
        }
        $this->checkRuntime();
        if ($bootstrap->attached()) {
            $this->checkSync();
            $this->checkOrphans();
        }
        $this->checkStacks();

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
        try {
            foreach ((new Bootstrap($this->paths(), $this->config()))->attach() as $line) {
                $this->say("fix: {$line}");
            }
        } catch (Throwable $e) {
            $this->say('fix: attach failed: '.$e->getMessage());
        }
        foreach (Steps::make($this->paths(), $this->config()) as $step) {
            foreach ($step->run() as $line) {
                $this->say("fix: {$line}");
            }
        }
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
            $hooksPath !== Bootstrap::HOOKS_PATH => ['warn', "core.hooksPath is {$hooksPath}: laravel-kanban's commit-msg and pre-push do not run (`vendor/bin/kanban attach --force` switches)"],
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
        }
    }

    private function checkOrphans(): void
    {
        $paths = $this->paths();
        $snapshot = $this->store()->snapshot();
        $orphans = 0;
        foreach (glob($paths->worktrees().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $card = $snapshot->card(strtoupper(basename($dir)));
            if ($card !== null && (! in_array($card->stage(), ['doing', 'review'], true) || ($card->work()['worktree'] ?? null) !== $paths->relative($dir))) {
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
        $problems = self::composeProblems((string) file_get_contents($main.'/'.$compose), self::portVariables($stack));
        foreach ($problems as $problem) {
            $this->add('fail', "{$compose}: {$problem}");
        }
        if ($problems === []) {
            $this->add('ok', "{$compose} is worktree-safe");
        }
        $overlays = array_diff(array_map('realpath', [...glob($main.'/docker-compose*.y*ml') ?: [], ...glob($main.'/compose*.y*ml') ?: []]), [realpath($main.'/'.$compose)]);
        foreach ($overlays as $overlay) {
            foreach (self::externalVolumeProblems((string) file_get_contents($overlay)) as $problem) {
                $this->add('warn', basename($overlay).": {$problem}");
            }
        }
        $env = DotEnv::parse($main.'/.env');
        $this->add(...(($env['COMPOSE_PROJECT_NAME'] ?? '') !== ''
            ? ['ok', "COMPOSE_PROJECT_NAME={$env['COMPOSE_PROJECT_NAME']} in .env"]
            : ['fail', 'COMPOSE_PROJECT_NAME missing from .env: the main stack has no project name (add e.g. COMPOSE_PROJECT_NAME='.basename($main).'-local)']));
        $this->checkAddressPools();
    }

    /**
     * Variables whose value differs per worktree and is a port: stack.ports, and stack.env entries built from one
     * (`DB_PORT` = `{DB_HOST_PORT}`).
     *
     * @param  array<string, mixed>  $stack
     * @return list<string>
     */
    private static function portVariables(array $stack): array
    {
        $ports = array_map('strval', array_keys((array) ($stack['ports'] ?? [])));
        $derived = [];
        foreach ((array) ($stack['env'] ?? []) as $key => $template) {
            foreach ($ports as $port) {
                if (str_contains((string) $template, '{'.$port.'}')) {
                    $derived[] = (string) $key;
                }
            }
        }

        return array_values(array_unique([...$ports, ...$derived]));
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

    /**
     * Keys that pin one name or host port for every stack (so worktree stacks collide or hijack main), and a missing
     * required project name. A line-based reading of the YAML: enough for the keys compose files use.
     *
     * @param  list<string>|null  $portVars  the stack.ports variables; a host port taken from any other variable is a problem (null: not checked)
     * @return list<string>
     */
    public static function composeProblems(string $yaml, ?array $portVars = null): array
    {
        $problems = [];
        $section = null;
        $name = null;
        $levels = [];
        $item = null;
        $services = [];
        $ports = null;
        foreach (self::composeLines($yaml) as [$index, $raw]) {
            $line = rtrim(self::withoutComment($raw));
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $key = preg_match('/^\s*([A-Za-z0-9_.-]+)\s*:(.*)$/', $line, $m) ? $m[1] : null;
            $at = 'line '.($index + 1);
            if ($ports !== null && ($indent > $ports['indent'] || ($indent === $ports['indent'] && str_starts_with(ltrim($line), '-')))) {
                $entry = trim(ltrim(ltrim($line), '-'));
                [$port, $vars] = preg_match('/^([a-z_]+)\s*:(.*)$/', $entry, $long) === 1
                    ? ($long[1] === 'published' ? [$long[2], self::variables($long[2])] : ['', []])
                    : self::hostPort($entry);
                array_push($problems, ...self::portProblems($port, $vars, $portVars, $ports['service'], $at));

                continue;
            }
            $ports = null;
            if ($indent === 0) {
                $section = $key;
                $levels = [];
                $item = null;
                if ($key === 'name') {
                    $name = trim($m[2]);
                }

                continue;
            }
            $levels[0] ??= $indent;
            if ($indent === $levels[0]) {
                $item = $key;

                continue;
            }
            $levels[1] ??= $indent;
            $depth = $indent === $levels[1] ? 1 : 2;
            if ($key === 'container_name') {
                $problems[] = "container_name on service {$item} ({$at}): one fixed name for every stack; remove it";
            }
            if ($depth !== 1 || $item === null) {
                continue;
            }
            if (in_array($section, ['volumes', 'networks'], true) && $key === 'name' && ! str_contains($m[2], '${COMPOSE_PROJECT_NAME')) {
                $problems[] = rtrim($section, 's')." {$item} has name: ({$at}): shared by every stack; remove it, or derive it from \${COMPOSE_PROJECT_NAME}";
            }
            if ($section === 'services' && in_array($key, ['build', 'image'], true)) {
                $services[$item][$key] = $at;
            }
            if ($section === 'services' && $key === 'ports') {
                $ports = ['indent' => $indent, 'service' => $item];
                $flow = trim($m[2]);
                foreach (str_starts_with($flow, '[') ? explode(',', trim($flow, '[] ')) : [] as $entry) {
                    [$port, $vars] = self::hostPort($entry);
                    array_push($problems, ...self::portProblems($port, $vars, $portVars, $item, $at));
                }
            }
        }
        foreach ($services as $service => $keys) {
            if (isset($keys['build'], $keys['image'])) {
                $problems[] = "image: on built service {$service} ({$keys['image']}): every stack would tag the same image; remove it";
            }
        }
        if ($name === null || ! str_contains($name, '${COMPOSE_PROJECT_NAME:?')) {
            $problems[] = 'top-level name: must be "${COMPOSE_PROJECT_NAME:?…}" so a worktree without its .env fails instead of taking over main\'s stack';
        }

        return $problems;
    }

    /**
     * Top-level `external: true` volumes whose name (`name:`, else the key) is fixed. In a file layered over the
     * local compose file such a name misses the local stack's volumes, which compose names `<project>_<volume>`.
     *
     * @return list<string>
     */
    public static function externalVolumeProblems(string $yaml): array
    {
        $volumes = [];
        $section = null;
        $level = null;
        $item = null;
        foreach (explode("\n", $yaml) as $index => $raw) {
            if (preg_match('/^( *)([A-Za-z0-9_.-]+)\s*:\s*(.*)$/', rtrim($raw), $m) !== 1) {
                continue;
            }
            $indent = strlen($m[1]);
            if ($indent === 0) {
                [$section, $level, $item] = [$m[2], null, null];

                continue;
            }
            if ($section !== 'volumes') {
                continue;
            }
            $level ??= $indent;
            $value = trim((string) preg_replace('/\s+#.*$/', '', $m[3]), " \"'");
            if ($indent === $level) {
                $item = $m[2];
                $volumes[$item] = ['line' => $index + 1, 'name' => $item, 'external' => false];
            } elseif ($m[2] === 'name') {
                $volumes[$item]['name'] = $value;
            } elseif ($m[2] === 'external') {
                $volumes[$item]['external'] = strtolower($value) === 'true';
            }
        }
        $problems = [];
        foreach ($volumes as $key => $volume) {
            if ($volume['external'] && ! str_contains($volume['name'], '$')) {
                $problems[] = "external volume {$key} has the fixed name {$volume['name']} (line {$volume['line']}): the local stack's volumes are named <project>_<volume>; use \"\${COMPOSE_PROJECT_NAME}_<volume>\" or a variable";
            }
        }

        return $problems;
    }

    /**
     * The host port of a short-syntax `ports:` entry (`8080` in `127.0.0.1:8080:80`, each variable expression as `$`)
     * and the names of the variables it is taken from; ['', []] when there is none.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function hostPort(string $entry): array
    {
        [$spec, $names] = self::collapseVariables(trim($entry, " \t\"'"));
        $spec = (string) preg_replace(['/^\[[^\]]*\]/', '#/\w+$#'], ['ip', ''], $spec);
        $parts = explode(':', $spec);
        $host = match (count($parts)) {
            2 => 0,
            3 => 1,
            default => null,
        };
        if ($host === null) {
            return ['', []];
        }
        $before = substr_count(implode(':', array_slice($parts, 0, $host)), '$');

        return [$parts[$host], array_slice($names, $before, substr_count($parts[$host], '$'))];
    }

    /**
     * Every top-level `${…}` (defaults and all) or `$NAME` in $value replaced by `$`, and the names of those variables
     * in order. A variable inside another's default is not one of them.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function collapseVariables(string $value): array
    {
        $out = '';
        $names = [];
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            if ($value[$i] !== '$') {
                $out .= $value[$i];

                continue;
            }
            if (($value[$i + 1] ?? '') === '$') {
                // `$$` is compose's escape for a literal dollar sign, not a variable.
                $out .= '_';
                $i++;

                continue;
            }
            if (($value[$i + 1] ?? '') === '{') {
                $depth = 0;
                for ($j = $i + 1; $j < $n; $j++) {
                    $depth += $value[$j] === '{' ? 1 : ($value[$j] === '}' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $names[] = preg_match('/^\w+/', substr($value, $i + 2), $m) === 1 ? $m[0] : '';
                $i = min($j, $n - 1);
            } elseif (preg_match('/^\w+/', substr($value, $i + 1), $m) === 1) {
                $names[] = $m[0];
                $i += strlen($m[0]);
            } else {
                $out .= '$';

                continue;
            }
            $out .= '$';
        }

        return [$out, $names];
    }

    /** @return list<string> the variables in a compose value that are not inside another variable's default */
    private static function variables(string $value): array
    {
        return array_values(array_filter(self::collapseVariables($value)[1]));
    }

    /**
     * A literal host port or range, or a host port taken from a variable that is not in stack.ports (so every stack
     * publishes the same port).
     *
     * @param  list<string>  $vars
     * @param  list<string>|null  $portVars
     * @return list<string>
     */
    private static function portProblems(string $port, array $vars, ?array $portVars, ?string $service, string $at): array
    {
        $port = trim($port, " \t\"'");
        if (preg_match('/^\d+(-\d+)?$/', $port) === 1) {
            return ["fixed host port {$port} on service {$service} ({$at}): one port for every stack; publish it from a stack.ports variable"];
        }
        $unknown = $portVars === null ? [] : array_values(array_diff(array_filter($vars), $portVars));

        return $unknown === [] ? [] : ["host port variable {$unknown[0]} on service {$service} ({$at}): not a stack.ports variable, so every stack publishes the same port; add it to stack.ports or publish from one that is"];
    }

    /** The line without a trailing ` # comment` (a `#` inside quotes is text). */
    private static function withoutComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && $i > 0 && ctype_space($line[$i - 1])) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return $line;
    }

    /**
     * The YAML as [line index, text] pairs with flow-style mappings (`key: { a: 1, b: [x] }`, `- { a: 1 }`) written out
     * as block lines that keep the original line number.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function composeLines(string $yaml): array
    {
        $out = [];
        foreach (explode("\n", $yaml) as $index => $raw) {
            self::expandFlow($index, rtrim($raw), $out);
        }

        return $out;
    }

    /** @param  list<array{0: int, 1: string}>  $out */
    private static function expandFlow(int $index, string $line, array &$out): void
    {
        $code = self::withoutComment($line);
        if (preg_match('/^(\s*)(-\s+)?(?:([A-Za-z0-9_.-]+)\s*:\s*)?\{(.*)\}\s*$/', $code, $m) !== 1 || ($m[2] === '' && $m[3] === '')) {
            $out[] = [$index, $line];

            return;
        }
        $indent = strlen($m[1]);
        if ($m[3] !== '') {
            $out[] = [$index, $m[1].$m[2].$m[3].':'];
            $indent += strlen($m[2]) + 2;
            $prefix = '';
        } else {
            $indent += strlen($m[2]);
            $prefix = $m[1].'- ';
        }
        foreach (self::splitTop($m[4]) as $n => $pair) {
            $lead = $prefix !== '' && $n === 0 ? $prefix : str_repeat(' ', $indent);
            self::expandFlow($index, $lead.trim($pair), $out);
        }
    }

    /** @return list<string> $text split on commas outside quotes, brackets and braces */
    private static function splitTop(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        foreach (str_split($text) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }

        return array_values(array_filter([...$parts, $current], fn (string $part) => trim($part) !== ''));
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
        $this->add(...($free < self::MIN_FREE_NETWORKS
            ? ['warn', "docker address pools: {$free} free networks (< ".self::MIN_FREE_NETWORKS.") in {$pools}: widen default-address-pools in /etc/docker/daemon.json (README)"]
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
