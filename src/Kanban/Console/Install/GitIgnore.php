<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

/** Main's `.gitignore` keeps the board worktree, the code worktrees and this checkout's Claude Code settings out of main. */
final class GitIgnore extends Step
{
    /** Entry => the spellings that count as present. */
    public const ENTRIES = [
        '/docs/kanban/' => ['/docs/kanban/', '/docs/kanban', 'docs/kanban/', 'docs/kanban'],
        '/.claude/worktrees' => ['/.claude/worktrees', '/.claude/worktrees/', '.claude/worktrees', '.claude/worktrees/'],
        '/.claude/settings.local.json' => ['/.claude/settings.local.json', '.claude/settings.local.json'],
    ];

    public function run(bool $dryRun = false, bool $force = false): array
    {
        $missing = $this->missing();
        if ($missing === []) {
            return ['.gitignore ok'];
        }
        if ($dryRun) {
            return ['would add to .gitignore: '.implode(' ', $missing)];
        }
        $current = (string) $this->read('.gitignore');
        $prefix = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
        $this->write('.gitignore', $current.$prefix.implode("\n", $missing)."\n");

        return ['.gitignore += '.implode(' ', $missing)];
    }

    public function check(): array
    {
        $missing = $this->missing();

        return $missing === []
            ? [['ok', '.gitignore '.implode(' ', array_keys(self::ENTRIES))]]
            : [['fail', '.gitignore lacks '.implode(' ', $missing).' (run `vendor/bin/kanban doctor --fix`)']];
    }

    /** @return list<string> */
    private function missing(): array
    {
        $lines = array_map('trim', explode("\n", (string) $this->read('.gitignore')));

        return array_keys(array_filter(self::ENTRIES, fn (array $spellings) => array_intersect($lines, $spellings) === []));
    }
}
