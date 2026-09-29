<?php

namespace PetarSpasic\Kanban\Guard;

/**
 * One `git` invocation: its class (R read, C commit, M main-session only), the
 * redirect it uses (-C, --git-dir, -c, GIT_*=, …) and the directory it acts on.
 */
final class GitCall
{
    public const READ = 'R';

    public const COMMIT = 'C';

    public const MAIN = 'M';

    private const READ_SUBCOMMANDS = [
        'status', 'diff', 'log', 'show', 'blame', 'grep', 'ls-files', 'ls-tree', 'rev-parse', 'rev-list', 'describe',
        'merge-base', 'cat-file', 'shortlog', 'name-rev', 'check-ignore', 'diff-tree', 'for-each-ref', 'var', 'version',
        'help', 'show-ref', 'diff-files', 'diff-index', 'check-attr', 'count-objects', 'whatchanged', 'range-diff', 'cherry',
    ];

    private const COMMIT_SUBCOMMANDS = ['add', 'commit', 'rm', 'mv', 'restore'];

    private const REDIRECT_OPTIONS = ['-C', '-c', '--git-dir', '--work-tree', '--namespace', '--config-env', '--exec-path', '--super-prefix'];

    public const HARMLESS_ENV = ['GIT_PAGER', 'GIT_TERMINAL_PROMPT', 'GIT_EDITOR'];

    /**
     * @param  list<string>  $args
     */
    private function __construct(
        public readonly ?string $subcommand,
        public readonly array $args,
        public readonly string $class,
        public readonly ?string $redirect,
        public readonly ?string $dir,
        public readonly bool $force,
        public readonly bool $noVerify,
    ) {}

    /**
     * @param  array<string, mixed>  $cmd  a command from ShellParser whose program is git
     */
    public static function parse(array $cmd, ?string $dir, string $home): self
    {
        $redirect = null;

        foreach ($cmd['assigns'] as $assign) {
            if (str_starts_with($assign['name'], 'GIT_') && ! in_array($assign['name'], self::HARMLESS_ENV, true)) {
                $redirect ??= $assign['name'].'=';
            }
        }

        $words = $cmd['words'];
        $count = count($words);
        $subcommand = null;
        $k = 1;

        while ($k < $count) {
            $a = $words[$k]['v'];

            if ($a === '-C') {
                $redirect ??= '-C';
                $dir = isset($words[$k + 1]) ? ShellParser::path($words[$k + 1], $dir, $home) : null;
                $k += 2;

                continue;
            }

            $option = explode('=', $a, 2)[0];
            if (in_array($option, self::REDIRECT_OPTIONS, true)) {
                $redirect ??= $option;
                $k += ($option === $a && $option !== '--exec-path') ? 2 : 1;

                continue;
            }

            if (in_array($a, ['--version', '-v'], true)) {
                $subcommand = 'version';
                break;
            }
            if (in_array($a, ['--help', '-h'], true)) {
                $subcommand = 'help';
                break;
            }
            if (str_starts_with($a, '-')) {
                $k++;

                continue;
            }

            $subcommand = $words[$k]['dyn'] ? '$dynamic' : $a;
            $k++;
            break;
        }

        $args = array_map(fn (array $w): string => $w['v'], array_slice($words, $k));

        return new self(
            $subcommand,
            $args,
            self::classify($subcommand, $args, $cmd['extra']),
            $redirect,
            $dir,
            $subcommand === 'add' && self::hasFlag($args, 'f', '--force', ''),
            $subcommand === 'commit' && self::hasFlag($args, 'n', '--no-verify', 'mFcCt'),
        );
    }

    /**
     * @param  list<string>  $args
     */
    private static function classify(?string $subcommand, array $args, bool $extra): string
    {
        if ($subcommand === null) {
            return $extra ? self::MAIN : self::READ;
        }

        if (in_array($subcommand, self::READ_SUBCOMMANDS, true)) {
            return self::READ;
        }

        if (in_array($subcommand, self::COMMIT_SUBCOMMANDS, true)) {
            return self::COMMIT;
        }

        $read = match ($subcommand) {
            'branch' => self::listing($args, ['-d', '-D', '--delete', '-m', '-M', '--move', '-c', '-C', '--copy', '-f', '--force', '-u', '--set-upstream-to', '--unset-upstream', '--edit-description', '-t', '--track', '--no-track', '--create-reflog'], 'dDmMcCfut', ['-l', '--list', '--show-current', '--contains', '--no-contains', '--merged', '--no-merged', '--points-at'], ['--contains', '--no-contains', '--merged', '--no-merged', '--points-at', '--sort', '--format']),
            'tag' => self::listing($args, ['-d', '--delete', '-a', '--annotate', '-s', '--sign', '-u', '--local-user', '-f', '--force', '-m', '--message', '-F', '--file', '-e', '--edit'], 'dasufmFe', ['-l', '--list', '--contains', '--no-contains', '--merged', '--no-merged', '--points-at'], ['--contains', '--no-contains', '--merged', '--no-merged', '--points-at', '--sort', '--format']),
            'remote' => array_diff($args, ['-v', '--verbose']) === [] || in_array(self::positional($args)[0] ?? null, ['show', 'get-url'], true),
            'stash' => in_array($args[0] ?? null, ['list', 'show'], true),
            'worktree' => ($args[0] ?? null) === 'list',
            'config' => self::configRead($args),
            'reflog' => ! in_array($args[0] ?? null, ['expire', 'delete', 'drop'], true),
            default => false,
        };

        return $read ? self::READ : self::MAIN;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $mutating
     * @param  list<string>  $listing
     * @param  list<string>  $withValue
     */
    private static function listing(array $args, array $mutating, string $mutatingShort, array $listing, array $withValue): bool
    {
        $positional = false;
        $isListing = false;
        $count = count($args);

        for ($k = 0; $k < $count; $k++) {
            $a = $args[$k];
            $option = explode('=', $a, 2)[0];

            if (in_array($option, $mutating, true) || (preg_match('/^-[a-zA-Z]+$/', $a) && strpbrk(substr($a, 1), $mutatingShort) !== false)) {
                return false;
            }
            if (in_array($option, $listing, true) || preg_match('/^-n\d*$/', $a)) {
                $isListing = true;
            }
            if (in_array($a, $withValue, true) && isset($args[$k + 1]) && ! str_starts_with($args[$k + 1], '-')) {
                $k++;

                continue;
            }
            if (! str_starts_with($a, '-')) {
                $positional = true;
            }
        }

        return $isListing || ! $positional;
    }

    /**
     * @param  list<string>  $args
     */
    private static function configRead(array $args): bool
    {
        $positional = self::positional($args, ['-f', '--file', '--blob', '--type', '--default']);

        if (in_array($positional[0] ?? null, ['get', 'list'], true)) {
            return true;
        }
        if (in_array($positional[0] ?? null, ['set', 'unset', 'rename-section', 'remove-section', 'edit'], true)) {
            return false;
        }
        if (array_intersect($args, ['--get', '--get-all', '--get-regexp', '--get-urlmatch', '--get-color', '--get-colorbool', '--list', '-l']) !== []) {
            return true;
        }
        if (array_intersect($args, ['--unset', '--unset-all', '--add', '--replace-all', '--edit', '-e', '--rename-section', '--remove-section']) !== []) {
            return false;
        }

        return count($positional) === 1;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $withValue
     * @return list<string>
     */
    private static function positional(array $args, array $withValue = []): array
    {
        $positional = [];
        $count = count($args);

        for ($k = 0; $k < $count; $k++) {
            if (in_array($args[$k], $withValue, true)) {
                $k++;
            } elseif (! str_starts_with($args[$k], '-')) {
                $positional[] = $args[$k];
            }
        }

        return $positional;
    }

    /**
     * A short flag inside option clusters (stopping at a value-taking letter) or its long form.
     *
     * @param  list<string>  $args
     */
    private static function hasFlag(array $args, string $short, string $long, string $valueLetters): bool
    {
        $valueLong = ['--message', '--file', '--author', '--date', '--template', '--reuse-message', '--reedit-message', '--fixup', '--squash', '--trailer', '--cleanup', '--pathspec-from-file', '--chmod'];
        $count = count($args);

        for ($k = 0; $k < $count; $k++) {
            $a = $args[$k];

            if ($a === '--') {
                return false;
            }
            if ($a === $long) {
                return true;
            }
            if (in_array($a, $valueLong, true)) {
                $k++;

                continue;
            }
            if (! preg_match('/^-[a-zA-Z]/', $a)) {
                continue;
            }

            $letters = substr($a, 1);
            $length = strlen($letters);
            for ($j = 0; $j < $length; $j++) {
                if ($letters[$j] === $short) {
                    return true;
                }
                if ($valueLetters !== '' && str_contains($valueLetters, $letters[$j])) {
                    if ($j === $length - 1) {
                        $k++;
                    }
                    break;
                }
            }
        }

        return false;
    }
}
