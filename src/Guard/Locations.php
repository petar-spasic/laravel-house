<?php

namespace PetarSpasic\Kanban\Guard;

/**
 * Classifies paths relative to the main checkout: board, gitdir, main, own-wt, other-wt, outside.
 */
final class Locations
{
    public const BOARD = 'board';

    public const GITDIR = 'gitdir';

    public const MAIN = 'main';

    public const OWN = 'own-wt';

    public const OTHER = 'other-wt';

    public const OUTSIDE = 'outside';

    public readonly string $board;

    /** @var list<string>|null */
    private ?array $worktrees = null;

    public function __construct(public readonly string $main)
    {
        $this->board = $main.'/docs/kanban';
    }

    /**
     * The main checkout containing $dir: the directory whose .git is a directory,
     * reached through commondir when $dir is inside a linked worktree.
     */
    public static function findMain(string $dir): ?string
    {
        $dir = realpath($dir) ?: null;

        while ($dir !== null) {
            $git = $dir.'/.git';

            if (is_dir($git)) {
                return $dir;
            }

            if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m)) {
                $gitdir = trim($m[1]);
                $gitdir = $gitdir[0] === '/' ? $gitdir : $dir.'/'.$gitdir;
                $commondir = @file_get_contents($gitdir.'/commondir');
                $common = $commondir === false ? false : realpath(str_starts_with(trim($commondir), '/') ? trim($commondir) : $gitdir.'/'.trim($commondir));

                return $common !== false && basename($common) === '.git' ? dirname($common) : $dir;
            }

            $parent = dirname($dir);
            $dir = $parent === $dir ? null : $parent;
        }

        return null;
    }

    /**
     * Absolute path with symlinks resolved as far as the path exists.
     */
    public function canonical(string $path): string
    {
        $path = ShellParser::normalize($path);
        $rest = '';

        while ($path !== '/' && ! file_exists($path)) {
            $rest = '/'.basename($path).$rest;
            $path = dirname($path);
        }

        $real = realpath($path) ?: $path;

        return ($real === '/' ? '' : $real).$rest ?: '/';
    }

    /**
     * @param  ?string  $own  the bound worktree (canonical), when the actor has one
     * @param  bool  $anyLinkedIsOwn  true for ordinary subagents: every linked worktree counts as their own
     */
    public function classify(string $path, ?string $own, bool $anyLinkedIsOwn = false): string
    {
        $path = $this->canonical($path);

        if (preg_match('#/\.git(/|$)#', $path)) {
            return self::GITDIR;
        }

        if (self::within($path, $this->board)) {
            return self::BOARD;
        }

        $worktree = $this->worktreeOf($path);

        if ($worktree !== null) {
            return $anyLinkedIsOwn || $worktree === $own ? self::OWN : self::OTHER;
        }

        return self::within($path, $this->main) ? self::MAIN : self::OUTSIDE;
    }

    public function worktreeOf(string $path): ?string
    {
        $found = null;

        foreach ($this->worktrees() as $root) {
            if (self::within($path, $root) && strlen($root) > strlen($found ?? '')) {
                $found = $root;
            }
        }

        $claude = $this->main.'/.claude/worktrees/';
        if ($found === null && str_starts_with($path, $claude) && strlen($path) > strlen($claude)) {
            $found = $claude.explode('/', substr($path, strlen($claude)))[0];
        }

        return $found;
    }

    public static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    /**
     * Linked worktrees registered in the main repository, the board excluded.
     *
     * @return list<string>
     */
    private function worktrees(): array
    {
        if ($this->worktrees !== null) {
            return $this->worktrees;
        }

        $this->worktrees = [];

        foreach (glob($this->main.'/.git/worktrees/*/gitdir') ?: [] as $file) {
            $gitFile = trim((string) @file_get_contents($file));
            if ($gitFile === '') {
                continue;
            }
            $root = $this->canonical(dirname($gitFile));
            if ($root !== $this->board && $root !== $this->main) {
                $this->worktrees[] = $root;
            }
        }

        return $this->worktrees;
    }
}
