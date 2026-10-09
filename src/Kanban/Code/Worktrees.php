<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Illuminate\Support\Str;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;

/**
 * Code worktrees under `<main>/.claude/worktrees/` and the slot, `.env` and Docker stack that go with each. A card's
 * worktree is a clone of main (its own `.git`, objects hardlinked), so its agents run git
 * inside their stack without reaching main's refs, config or runtime. Other worktrees are git worktrees.
 */
final class Worktrees
{
    /** The variable the local compose file mounts a worktree at, at its host path, for the card agents' shells. */
    public const MOUNT = 'KANBAN_WORKTREE_PATH';

    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    /** @var array<string, mixed>|null */
    private ?array $stack = null;

    public function __construct(public readonly Paths $paths, private readonly array $config) {}

    /** Git in main, or in a card's directory, where its agent controls the config (Git::untrusted). */
    public function git(?string $cwd = null): Git
    {
        return $cwd === null || $cwd === $this->paths->main ? new Git($this->paths->main) : Git::untrusted($cwd);
    }

    public function mainBranch(): string
    {
        return (string) ($this->config['main_branch'] ?? 'main');
    }

    /** Why the main checkout cannot take main-session git work now, or null. */
    public function notOnMain(): ?string
    {
        $branch = $this->git()->line(['symbolic-ref', '--short', '-q', 'HEAD']);
        if ($branch !== $this->mainBranch()) {
            return "the main checkout is on '".($branch ?? 'detached HEAD')."', not {$this->mainBranch()}";
        }
        $gitDir = $this->paths->gitDir();
        foreach (['MERGE_HEAD' => 'a merge', 'rebase-merge' => 'a rebase', 'rebase-apply' => 'a rebase', 'CHERRY_PICK_HEAD' => 'a cherry-pick'] as $file => $what) {
            if (file_exists($gitDir.'/'.$file)) {
                return "{$what} is in progress in the main checkout";
            }
        }

        return null;
    }

    /** `card/<id-lc>-<slug≤40>` */
    public function branchFor(string $id, string $title): string
    {
        $slug = self::slug($title, 40);

        return ($this->config['worktrees']['branch_prefix'] ?? 'card/').strtolower($id).($slug === '' ? '' : '-'.$slug);
    }

    /** Str::slug of $title cut at the last whole word within $max characters (a longer first word is cut). */
    public static function slug(string $title, int $max): string
    {
        $slug = Str::slug($title);
        if (strlen($slug) <= $max) {
            return $slug;
        }
        $cut = substr($slug, 0, $max + 1);
        $end = strrpos($cut, '-');

        return trim($end === false || $end === 0 ? substr($slug, 0, $max) : substr($cut, 0, $end), '-');
    }

    /** The title's leading words within $max characters, for names shown at a glance (agent descriptions). */
    public static function label(string $title, int $max = 32): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if (mb_strlen($title) <= $max) {
            return $title;
        }
        $cut = mb_substr($title, 0, $max + 1);
        $end = mb_strrpos($cut, ' ');

        return rtrim($end === false || $end === 0 ? mb_substr($title, 0, $max) : mb_substr($cut, 0, $end), ' ,.;:-');
    }

    /** The Agent call that spawns $agent (kanban-worker, kanban-planner, kanban-evaluator, kanban-merger) on $path: the card's worktree, or the merge clone. */
    public static function spawnLine(Card $card, string $agent, string $path): string
    {
        $description = $card->id().match ($agent) {
            'kanban-evaluator' => ' review ', 'kanban-planner' => ' plan ', 'kanban-merger' => ' merge ', default => ' '
        }.self::label($card->title());

        return "Agent(subagent_type=\"{$agent}\", description=\"{$description}\", prompt=\"Card {$card->id()}. Worktree {$path}\")";
    }

    public function branchExists(string $branch): bool
    {
        return $this->git()->attempt(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch])->ok();
    }

    /** Is $path a registered worktree of this repository, or a card clone of it? */
    public function isWorktree(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        if ($this->isClone($real)) {
            return true;
        }
        foreach (explode("\n", $this->git()->line(['worktree', 'list', '--porcelain']) ?? '') as $line) {
            if (str_starts_with($line, 'worktree ') && realpath(substr($line, 9)) === $real) {
                return true;
            }
        }

        return false;
    }

    /** A card clone of this repository: its own `.git` directory, under this checkout's `.claude/worktrees/` (Paths::cloneMainOf). */
    public function isClone(string $path): bool
    {
        return is_dir($path.'/.git') && Paths::cloneMainOf($path) === $this->paths->main;
    }

    /** This checkout's merge clone (Paths::mergeClone): its stack is outside `stack.max_stacks`, and no card's branch is in it. */
    public function isMergeClone(string $path): bool
    {
        return (realpath($path) ?: $path) === (realpath($this->paths->mergeClone()) ?: $this->paths->mergeClone());
    }

    /**
     * A card's clone at $path on $branch: an existing branch is fetched from main, otherwise it is created from local
     * main. It carries main's identity and hooks path, and `kanban.main`, which tells its container it is in a clone.
     */
    public function add(string $path, string $branch): void
    {
        @mkdir(dirname($path), 0775, true);
        $main = $this->mainBranch();
        $this->git()->run(['clone', '-q', '--no-checkout', '--origin', 'origin', $this->paths->main, $path]);
        $clone = new Git($path);
        foreach (['user.name', 'user.email', 'core.hooksPath'] as $key) {
            if (($value = $this->git()->line(['config', '--get', $key])) !== null) {
                $clone->run(['config', $key, $value]);
            }
        }
        $clone->run(['config', Paths::CLONE_KEY, $this->paths->main]);
        $this->branchExists($branch)
            ? $clone->run(['checkout', '-q', '-b', $branch, 'refs/remotes/origin/'.$branch])
            : $clone->run(['checkout', '-q', '-b', $branch, 'refs/remotes/origin/'.$main]);
        $clone->run(['branch', '-q', '--force', $main, 'refs/remotes/origin/'.$main]);
        $clone->attempt(['branch', '-q', '--unset-upstream']);
        // scratch space that goes with the card: the container's TMPDIR (EnvWriter), out of git
        @mkdir($path.'/.tmp', 0775);
        file_put_contents($path.'/.git/info/exclude', "/.tmp/\n", FILE_APPEND);
    }

    /** `git worktree add` of a plain (non-card) worktree: an existing branch is checked out, otherwise it is created from local main. */
    private function addWorktree(string $path, string $branch): void
    {
        @mkdir(dirname($path), 0775, true);
        $args = $this->branchExists($branch)
            ? ['worktree', 'add', '-q', $path, $branch]
            : ['worktree', 'add', '-q', '-b', $branch, $path, 'refs/heads/'.$this->mainBranch()];
        $this->git()->run($args);
    }

    /**
     * Brings a card clone and main level: main's branch into the clone (its gates and diffs compare against it) and
     * the clone's branch into main (finish, stop and the merge checks read it there): $branch, the card's, else the one
     * the clone has checked out. A git worktree needs neither.
     */
    public function sync(string $path, ?string $branch = null): void
    {
        if (! $this->isClone(realpath($path) ?: $path) || $this->isMergeClone($path)) {
            return;
        }
        $main = $this->mainBranch();
        $clone = Git::untrusted($path);
        // by path, never the clone's `origin`: its remote config (an upload-pack, a URL) is the agent's
        $clone->attempt(['fetch', '-q', '--no-tags', '--no-recurse-submodules', $this->paths->main, "+refs/heads/{$main}:refs/heads/{$main}"]);
        $branch ??= $clone->line(['symbolic-ref', '--short', '-q', 'HEAD']);
        if ($branch !== null && $branch !== $main) {
            // the upload-pack serving it runs in the clone, with the clone's config: untrusted
            Git::untrusted($this->paths->main)->attempt(['fetch', '-q', '--no-tags', $path, "+refs/heads/{$branch}:refs/heads/{$branch}"]);
        }
    }

    /**
     * A plain (non-card) worktree `.claude/worktrees/<name>` on `worktree-<name>` from local main, with its
     * dependencies copied; an existing one is reused. Returns its realpath.
     */
    public function createNamed(string $name): string
    {
        $path = $this->paths->worktrees().'/'.$name;
        if (! $this->isWorktree($path)) {
            if (file_exists($path)) {
                throw new PolicyRefused("{$this->paths->relative($path)} exists but is not a worktree of this repository");
            }
            $this->addWorktree($path, 'worktree-'.$name);
        }
        $this->copyDependencies($path);

        return realpath($path) ?: $path;
    }

    /**
     * `cp -a` (with `--reflink=auto` on Linux) of `worktrees.copy` from main (only those; never .env*, storage, public/hot, docs).
     *
     * @return list<string> what was copied
     */
    public function copyDependencies(string $path): array
    {
        $copied = [];
        foreach ((array) ($this->config['worktrees']['copy'] ?? []) as $item) {
            $item = trim((string) $item, '/');
            if ($item === '' || str_starts_with($item, '.env') || in_array($item, ['storage', 'public/hot', 'docs'], true)) {
                continue;
            }
            $from = $this->paths->main.'/'.$item;
            $to = $path.'/'.$item;
            if (! file_exists($from) || file_exists($to)) {
                continue;
            }
            @mkdir(dirname($to), 0775, true);
            // Copied beside the worktrees under a name of its own and renamed into place: a killed copy is redone
            // instead of taken for complete, and concurrent copies and `git status` never see it.
            $partial = $this->paths->worktrees().'/.copying/'.basename($path).'-'.str_replace('/', '_', $item).'-'.getmypid();
            @mkdir(dirname($partial), 0775, true);
            (new Process(['rm', '-rf', $partial]))->run();
            $cp = new Process(['cp', '-a', ...(PHP_OS_FAMILY === 'Linux' ? ['--reflink=auto'] : []), $from, $partial], null, null, null, 600);
            $cp->run();
            if (! $cp->isSuccessful() || ! rename($partial, $to)) {
                (new Process(['rm', '-rf', $partial]))->run();

                throw new StackFailed("copying {$item} failed: ".trim($cp->getErrorOutput()));
            }
            $copied[] = $item;
        }

        return $copied;
    }

    public function stackEnabled(): bool
    {
        return Stack::enabled((array) ($this->config['stack'] ?? []), $this->paths->main);
    }

    /** `kanban.stack` with every host port variable of main's compose file in `ports`. */
    public function stackConfig(): array
    {
        return $this->stack ??= ComposeFile::withHostPorts((array) ($this->config['stack'] ?? []), $this->paths->main);
    }

    public function registry(): PortRegistry
    {
        return new PortRegistry($this->stackConfig());
    }

    public function env(): EnvWriter
    {
        return new EnvWriter($this->paths->main, $this->config);
    }

    public function stack(string $path, ?string $project = null): Stack
    {
        return new Stack($path, $project ?? $this->env()->project($path), $this->stackConfig(), $this->paths->main);
    }

    /**
     * Slot + `.env` for the worktree (idempotent). Null when stacks are disabled.
     *
     * @param  list<int>  $avoid
     * @return array<string, mixed>|null the registry entry, plus `url`
     */
    public function prepare(string $path, ?string $branch, ?string $card, array $avoid = []): ?array
    {
        if (! $this->stackEnabled()) {
            return null;
        }
        foreach (['.cache/composer', '.npm'] as $cache) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                @mkdir($home.'/'.$cache, 0775, true);
            }
        }
        $env = $this->env();
        $entry = $this->registry()->allocate($path, [
            'project' => $env->project($path), 'repo' => $this->paths->main, 'branch' => $branch, 'card' => $card,
        ] + ($this->isMergeClone($path) ? ['purpose' => PortRegistry::MERGE] : []), $avoid);
        $env->write($path, $entry['ports']);

        return $entry + ['url' => $env->url($path, $entry['ports'])];
    }

    /**
     * Resource precheck (not for a recreate, nor the merge stack: a busy machine still drains its merge queue), project name check, `up -d --build` (no wait), with `--force-recreate` too
     * when the docker files changed since the recorded up. "port is already allocated" → the next slot, `.env`
     * rewritten, one retry. The stack record is written after a successful up.
     *
     * @return array<string, mixed> the registry entry the stack runs on, plus `url`
     */
    public function up(string $path, ?string $branch, ?string $card, bool $recreate = false): array
    {
        $entry = $this->prepare($path, $branch, $card) ?? throw new StackFailed('stacks are disabled (stack.compose_file unset or missing)');
        $hash = $this->stackRecord($path)['hash'] ?? null;
        $recreate = $recreate || ($hash !== null && $hash !== $this->dockerHash($path));
        $refusals = $recreate || $this->isMergeClone($path) ? [] : $this->registry()->resourceRefusals();
        if ($refusals !== []) {
            throw new StackFailed('not starting a stack: '.implode('; ', $refusals), $refusals);
        }
        $stack = $this->stack($path, $entry['project']);
        $stack->assertProject();
        $result = $stack->up($recreate);
        if (Stack::portAllocated($result)) {
            $stack->down();
            $entry = $this->prepare($path, $branch, $card, [(int) $entry['slot']]);
            $result = $stack->up($recreate);
        }
        if ($result['code'] !== 0) {
            throw new StackFailed("docker compose up failed for {$entry['project']}: ".self::tail($result['err'] ?: $result['out']));
        }
        $this->record($path, $entry['project']);

        return $entry;
    }

    /**
     * `.git/laravel-house/stacks/<worktree name>.json`, written once the stack is up: the container that Guard routes
     * the card agents' shells into (with `agents.shell`), and the hash of the docker files the stack was built from.
     */
    public function recordFile(string $path, ?string $main = null): string
    {
        return ($main ?? $this->paths->main).'/'.Paths::RUNTIME.'/stacks/'.basename($path).'.json';
    }

    /** @return array<string, mixed>|null */
    public function stackRecord(string $path): ?array
    {
        $file = $this->recordFile($path);
        $record = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($record) ? $record : null;
    }

    public function record(string $path, string $project): void
    {
        $compose = $path.'/'.($this->config['stack']['compose_file'] ?? '');
        $mounted = is_file($compose) && str_contains((string) file_get_contents($compose), self::MOUNT);
        Json::write($this->recordFile($path), json_encode([
            'worktree' => realpath($path) ?: $path,
            'project' => $project,
            'container' => $this->stack($path, $project)->container(),
            'shell' => $this->agentShell() === 'container' && $mounted ? 'container' : 'host',
            'hash' => $this->dockerHash($path),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /** `agents.shell`: container (the default) or host. */
    public function agentShell(): string
    {
        return ($this->config['agents']['shell'] ?? null) === 'host' ? 'host' : 'container';
    }

    public function forget(string $path, ?string $main = null): void
    {
        @unlink($this->recordFile($path, $main));
    }

    /**
     * Hash of the files whose change needs a rebuilt, recreated stack (MergeCheck::rebuildFiles), tracked or untracked;
     * ignored ones (vendor, node_modules) never count.
     */
    public function dockerHash(string $path): string
    {
        $listed = $this->git($path)->attempt(['ls-files', '-z', '--cached', '--others', '--exclude-standard'])->out;
        $files = array_unique(MergeCheck::rebuildFiles(array_filter(explode("\0", $listed)), $this->config['stack']['compose_file'] ?? null));
        sort($files);
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            if (is_file($path.'/'.$file)) {
                hash_update($hash, $file."\0".hash_file('sha256', $path.'/'.$file)."\n");
            }
        }

        return hash_final($hash);
    }

    /**
     * Recreates a stack whose docker files or lockfiles changed since it came up: the entry it now runs on, or null when
     * it was fresh or never came up.
     *
     * @return array<string, mixed>|null
     */
    public function freshen(string $path): ?array
    {
        $hash = $this->stackRecord($path)['hash'] ?? null;
        if ($hash === null || $hash === $this->dockerHash($path) || $this->merging($path) !== null) {
            return null;
        }

        return $this->up($path, $this->git($path)->line(['symbolic-ref', '--short', '-q', 'HEAD']), $this->registry()->find($path)['card'] ?? null, recreate: true);
    }

    /**
     * The files a merge in progress in the worktree leaves unmerged (empty once all are added, before the commit); null
     * when no merge is in progress. The stack is never rebuilt or recreated meanwhile: its container is where the
     * card's agents run git to conclude the merge, and a conflicted tree may not boot.
     *
     * @return list<string>|null
     */
    public function merging(string $path): ?array
    {
        $git = $this->git($path);
        if (! $git->attempt(['rev-parse', '-q', '--verify', 'MERGE_HEAD'])->ok()) {
            return null;
        }
        // the index alone: no content of the clone is read, so nothing its config names runs
        $files = [];
        foreach (array_filter(explode("\0", $git->attempt(['ls-files', '-u', '-z'])->out)) as $line) {
            $files[substr($line, strpos($line, "\t") + 1)] = true;
        }

        return array_keys($files);
    }

    /**
     * Polls `http://127.0.0.1:<WEB_PORT><stack.health_path>` until 200 or `stack.wait_timeout`: null once it answers,
     * else what it last answered. Throws StackFailed with the tail of the service's log when its container exits, dies
     * or restarts meanwhile: a container that will not boot is not starting.
     *
     * @param  array<string, mixed>  $entry
     */
    public function await(array $entry): ?string
    {
        $stack = $this->stack((string) $entry['worktree'], (string) $entry['project']);
        $restarts = $stack->state()['restarts'] ?? 0;
        $deadline = microtime(true) + (float) ($this->config['stack']['wait_timeout'] ?? 110);
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        do {
            $started = microtime(true);
            $http_response_header = [];
            $body = @file_get_contents($this->healthUrl($entry), false, $context);
            $status = preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : null;
            if ($body !== false && $status === 200) {
                return null;
            }
            $state = $stack->state();
            if ($state !== null && (! in_array($state['status'], ['created', 'running'], true) || $state['restarts'] > $restarts)) {
                $logs = $stack->compose(['logs', '--no-color', '--tail=20', $stack->service()], 60);
                throw new StackFailed("{$stack->service()} {$state['status']} (exit {$state['code']}) in {$entry['project']}; its last log lines:\n"
                    .rtrim($logs['out'].$logs['err']));
            }
            usleep((int) max(0, 1_000_000 - (microtime(true) - $started) * 1_000_000));
        } while (microtime(true) < $deadline);

        return $status === null ? 'no answer' : "answers {$status}";
    }

    /** @param  array<string, mixed>  $entry */
    public function healthUrl(array $entry): string
    {
        $port = $entry['ports']['WEB_PORT'] ?? throw new StackFailed('no WEB_PORT in stack.ports');

        return 'http://127.0.0.1:'.$port.($this->config['stack']['health_path'] ?? '/up');
    }

    /** Stack down with `stack.down`; the slot is released only after a successful down. */
    public function down(string $path, ?string $project = null): bool
    {
        $entry = $this->registry()->find($path);
        $project ??= $entry['project'] ?? null;
        if ($project === null || ! $this->stackEnabled()) {
            return true;
        }
        $result = is_file($path.'/'.$this->config['stack']['compose_file'])
            ? $this->stack($path, $project)->down()
            : Stack::downProject($project, (array) ($this->config['stack']['down'] ?? []));
        if ($result['code'] !== 0) {
            return false;
        }
        $this->forget($path);
        $this->registry()->release($path);

        return true;
    }

    /** @return list<string> uncommitted changes to tracked files: what a merge would carry, or a removed clone lose */
    public function changed(string $path): array
    {
        return array_values(array_filter(explode("\n", rtrim($this->status($path, ['--untracked-files=no'])))));
    }

    /**
     * Untracked files of a clone whose tracked files are committed: a check's leftovers, gone with the clone. The line
     * that names them, or null.
     */
    public function leftovers(string $path): ?string
    {
        if ($this->changed($path) !== []) {
            return null;
        }
        $untracked = array_map(fn (string $l) => substr($l, 3), $this->dirty($path));

        return $untracked === [] ? null : 'removed with the clone, untracked: '.implode(', ', array_slice($untracked, 0, 10)).(count($untracked) > 10 ? ' …' : '');
    }

    /** Tracked changes or untracked files (ignored files do not count). */
    public function dirty(string $path): array
    {
        return array_values(array_filter(explode("\n", rtrim($this->status($path, ['--untracked-files=all'])))));
    }

    /**
     * `git status --porcelain` of a worktree; a repository nested in it never counts.
     *
     * @param  list<string>  $args
     */
    private function status(string $path, array $args): string
    {
        return is_dir($path) ? $this->git($path)->attempt(['status', '--porcelain', '--ignore-submodules=all', ...$args])->out : '';
    }

    /** A clone's branch ($branch, the card's, else the one it has checked out) reaches main first, so removing it never loses a commit. */
    public function remove(string $path, bool $force = false, ?string $branch = null): void
    {
        if ($this->isMergeClone($path)) {
            return;
        }
        if (! $this->isClone(realpath($path) ?: $path)) {
            $this->git()->run(['worktree', 'remove', ...($force ? ['--force'] : []), $path]);

            return;
        }
        if (! $force && $this->dirty($path) !== []) {
            throw new GitFailed("{$this->paths->relative($path)} has uncommitted changes");
        }
        $this->sync($path, $branch);
        $rm = new Process(['rm', '-rf', $path]);
        $rm->run();
        if (! $rm->isSuccessful()) {
            throw new GitFailed("removing {$this->paths->relative($path)} failed: ".trim($rm->getErrorOutput()));
        }
    }

    public function prune(): void
    {
        $this->git()->attempt(['worktree', 'prune']);
    }

    /** Commits on $branch that main does not have. */
    public function commitsAhead(string $branch): int
    {
        return (int) ($this->git()->line(['rev-list', '--count', 'refs/heads/'.$this->mainBranch().'..refs/heads/'.$branch]) ?? 0);
    }

    public function deleteBranch(string $branch, bool $force = false): bool
    {
        return $this->git()->attempt(['branch', $force ? '-D' : '-d', $branch])->ok();
    }

    /** The worktree root that contains $dir, when it is a code worktree under `.claude/worktrees/`. */
    public function containing(string $dir): ?string
    {
        $top = $this->git($dir)->line(['rev-parse', '--show-toplevel']);
        $top = $top === null ? null : (realpath($top) ?: $top);

        return $top !== null && str_starts_with($top, $this->paths->worktrees().'/') ? $top : null;
    }

    public function head(string $rev, ?string $cwd = null): string
    {
        $sha = $this->git($cwd)->line(['rev-parse', '--verify', '-q', $rev]);

        return $sha ?? throw new GitFailed("cannot resolve {$rev}");
    }

    public function requireMain(): void
    {
        if (($reason = $this->notOnMain()) !== null) {
            throw new PolicyRefused($reason);
        }
    }

    public static function tail(string $text, int $lines = 5): string
    {
        return implode(' | ', array_slice(array_filter(array_map('trim', explode("\n", $text))), -$lines));
    }
}
