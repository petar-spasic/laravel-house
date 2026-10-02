<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;

final class Paths
{
    public const BOARD = 'docs/kanban';

    /** Git config key in a card clone naming its main checkout. */
    public const CLONE_KEY = 'kanban.main';

    public const RUNTIME = '.git/laravel-house';

    public const WORKTREES = '.claude/worktrees';

    /**
     * @param  string  $main  the main checkout (the directory whose .git is a directory)
     * @param  string  $cwd  where discovery started
     */
    public function __construct(
        public readonly string $main,
        public readonly string $cwd,
        public readonly bool $inRepo = true,
    ) {}

    /** Walks up from $from to the main checkout; a linked worktree resolves through its commondir. */
    public static function discover(string $from): self
    {
        $start = realpath($from) ?: $from;
        for ($dir = $start; ; $dir = dirname($dir)) {
            $git = $dir.'/.git';
            if (is_dir($git)) {
                return new self(self::cloneMainOf($dir) ?? $dir, $start);
            }
            if (is_file($git) && ($main = self::mainOf($git)) !== null) {
                return new self($main, $start);
            }
            if (dirname($dir) === $dir) {
                return new self($start, $start, false);
            }
        }
    }

    /** Main checkout of a linked worktree's `.git` file, or null when its gitdir is unreachable. */
    public static function mainOf(string $gitFile): ?string
    {
        $gitdir = self::gitdirOf($gitFile);
        if ($gitdir === null || ! is_dir($gitdir)) {
            return null;
        }
        $common = is_file($gitdir.'/commondir') ? trim((string) file_get_contents($gitdir.'/commondir')) : '../..';
        $common = str_starts_with($common, '/') ? $common : $gitdir.'/'.$common;
        $real = realpath($common);

        return $real === false ? null : dirname($real);
    }

    /**
     * Main checkout of a card clone: `kanban.main` in the clone's `.git/config`, when that directory is a checkout
     * whose `.claude/worktrees/` holds the clone. Null for any other repository, and inside a card's container, where
     * main is not mounted.
     */
    public static function cloneMainOf(string $dir): ?string
    {
        $config = @file_get_contents($dir.'/.git/config');
        if (! is_string($config) || ! preg_match('/^\[kanban\]\R(?:[ \t]+[^\[\r\n]*\R)*?[ \t]+main[ \t]*=[ \t]*(.+?)[ \t]*$/m', $config, $m)) {
            return null;
        }
        $main = realpath($m[1]);

        return $main !== false && is_dir($main.'/.git') && str_starts_with(realpath($dir) ?: $dir, $main.'/'.self::WORKTREES.'/') ? $main : null;
    }

    /** Inside a card's container: the nearest repository is a card clone whose main checkout is not there. */
    public static function inCardContainer(string $from): bool
    {
        for ($dir = realpath($from) ?: $from; ; $dir = dirname($dir)) {
            if (is_dir($dir.'/.git')) {
                $config = (string) @file_get_contents($dir.'/.git/config');

                return preg_match('/^\[kanban\]\R(?:[ \t]+[^\[\r\n]*\R)*?[ \t]+main[ \t]*=[ \t]*(.+?)[ \t]*$/m', $config, $m) === 1
                    && ! is_dir($m[1].'/.git');
            }
            if (dirname($dir) === $dir) {
                return false;
            }
        }
    }

    /** The `gitdir:` target of a `.git` file, as written (absolute or relative to the file). */
    public static function gitdirOf(string $gitFile): ?string
    {
        if (! is_file($gitFile) || ! preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($gitFile), $m)) {
            return null;
        }
        $gitdir = trim($m[1]);

        return str_starts_with($gitdir, '/') ? $gitdir : dirname($gitFile).'/'.$gitdir;
    }

    public function board(string $relative = ''): string
    {
        return $this->join($this->main.'/'.self::BOARD, $relative);
    }

    public function hasBoard(): bool
    {
        return is_file($this->board('kanban.json'));
    }

    public function gitDir(): string
    {
        return $this->main.'/.git';
    }

    /** Per-machine runtime directory (never committed). */
    public function runtime(string $relative = ''): string
    {
        return $this->join($this->main.'/'.self::RUNTIME, $relative);
    }

    /** Runtime directory, created when missing. */
    public function ensureRuntime(string $relative = ''): string
    {
        $dir = $this->runtime($relative);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public function lockFile(): string
    {
        return $this->runtime('lock');
    }

    public function journalFile(): string
    {
        return $this->runtime('journal.jsonl');
    }

    public function agents(string $agentId = ''): string
    {
        return $this->runtime($agentId === '' ? 'agents' : "agents/{$agentId}.json");
    }

    public function staged(string $file = ''): string
    {
        return $this->join($this->runtime('staged'), $file);
    }

    public function applied(string $file = ''): string
    {
        return $this->join($this->runtime('applied'), $file);
    }

    /** Local edits of cards that origin deleted, kept by sync for a person to read. */
    public function displaced(string $file = ''): string
    {
        return $this->join($this->runtime('displaced'), $file);
    }

    public function inbox(string $file = ''): string
    {
        return $this->join($this->runtime('inbox'), $file);
    }

    public function leaseFile(): string
    {
        return $this->runtime('lease.json');
    }

    public function worktrees(): string
    {
        return $this->main.'/'.self::WORKTREES;
    }

    /**
     * Code worktree of a card: `<main>/.claude/worktrees/<id without key, lowercase>-<title slug≤24>`, so the
     * directory, compose project and containers read as the card at a glance. Fixed at `start`; later
     * lookups go through the card's `work.worktree`.
     */
    public function worktree(string $cardId, string $title = ''): string
    {
        $suffix = strtolower(str_contains($cardId, '-') ? substr($cardId, strpos($cardId, '-') + 1) : $cardId);
        $slug = Worktrees::slug($title, 24);

        return $this->worktrees().'/'.$suffix.($slug === '' ? '' : '-'.$slug);
    }

    /** Relative to main when inside it, else unchanged. */
    public function relative(string $path): string
    {
        return str_starts_with($path, $this->main.'/') ? substr($path, strlen($this->main) + 1) : $path;
    }

    private function join(string $base, string $relative): string
    {
        return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
    }
}
