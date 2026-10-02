<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use FilesystemIterator;
use Illuminate\Support\Str;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

/** Code worktrees under `<main>/.claude/worktrees/` and the slot, `.env` and Docker stack that go with each. */
final class Worktrees
{
    /** The variable the local compose file mounts a worktree at, at its host path, for the card agents' shells. */
    public const MOUNT = 'KANBAN_WORKTREE_PATH';

    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    public function __construct(public readonly Paths $paths, private readonly array $config) {}

    public function git(?string $cwd = null): Git
    {
        return new Git($cwd ?? $this->paths->main);
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

    /** The Agent call that spawns $agent (kanban-worker, kanban-evaluator) on the card's worktree at $path. */
    public static function spawnLine(Card $card, string $agent, string $path): string
    {
        $description = $card->id().($agent === 'kanban-evaluator' ? ' review ' : ' ').self::label($card->title());

        return "Agent(subagent_type=\"{$agent}\", description=\"{$description}\", isolation=\"worktree\", prompt=\"Card {$card->id()}. Worktree {$path}\")";
    }

    public function branchExists(string $branch): bool
    {
        return $this->git()->attempt(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch])->ok();
    }

    /** Is $path a registered worktree of this repository? */
    public function isWorktree(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        foreach (explode("\n", $this->git()->line(['worktree', 'list', '--porcelain']) ?? '') as $line) {
            if (str_starts_with($line, 'worktree ') && realpath(substr($line, 9)) === $real) {
                return true;
            }
        }

        return false;
    }

    /** `git worktree add`: an existing branch is checked out, otherwise it is created from local main. */
    public function add(string $path, string $branch): void
    {
        @mkdir(dirname($path), 0775, true);
        $args = $this->branchExists($branch)
            ? ['worktree', 'add', '-q', $path, $branch]
            : ['worktree', 'add', '-q', '-b', $branch, $path, 'refs/heads/'.$this->mainBranch()];
        $this->git()->run($args);
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
            $this->add($path, 'worktree-'.$name);
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

    public function registry(): PortRegistry
    {
        return new PortRegistry((array) ($this->config['stack'] ?? []));
    }

    public function env(): EnvWriter
    {
        return new EnvWriter($this->paths->main, $this->config);
    }

    public function stack(string $path, ?string $project = null): Stack
    {
        return new Stack($path, $project ?? $this->env()->project($path), (array) ($this->config['stack'] ?? []), $this->paths->main);
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
        ], $avoid);
        $env->write($path, $entry['ports']);

        return $entry + ['url' => $env->url($path, $entry['ports'])];
    }

    /**
     * Resource precheck (not for a recreate), project name check, `up -d --build` (no wait), with `--force-recreate` too
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
        $refusals = $recreate ? [] : $this->registry()->resourceRefusals();
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

    /** Hash of the files whose change needs a rebuilt, recreated stack (MergeCheck::REBUILD and the compose file). */
    public function dockerHash(string $path): string
    {
        $files = [];
        foreach ([...MergeCheck::REBUILD, (string) ($this->config['stack']['compose_file'] ?? '')] as $pattern) {
            $full = $path.'/'.rtrim($pattern, '/');
            if ($pattern === '' || ! file_exists($full)) {
                continue;
            }
            if (is_file($full)) {
                $files[$pattern] = $full;

                continue;
            }
            $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            foreach ($tree as $file) {
                if ($file->isFile()) {
                    $files[substr($file->getPathname(), strlen($path) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($files);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $file) {
            hash_update($hash, $relative."\0".hash_file('sha256', $file)."\n");
        }

        return hash_final($hash);
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

    /** Tracked changes or untracked files (ignored files do not count). */
    public function dirty(string $path): array
    {
        $out = $this->git($path)->attempt(['status', '--porcelain', '--untracked-files=all']);

        return array_values(array_filter(explode("\n", rtrim($out->out))));
    }

    public function remove(string $path, bool $force = false): void
    {
        $this->git()->run(['worktree', 'remove', ...($force ? ['--force'] : []), $path]);
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
