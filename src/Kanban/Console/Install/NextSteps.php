<?php

namespace PetarSpasic\Kanban\Console\Install;

use PetarSpasic\Kanban\Store\Git\Bootstrap;
use PetarSpasic\Kanban\Support\Git;

/** The closing lines of `kanban:install`: what the owner reviews and commits on main. */
final class NextSteps extends Step
{
    /** The last line of `kanban:install` and `doctor --fix`. */
    public const RESTART = 'restart Claude Code (agents and hooks load at session start); then `vendor/bin/kanban lease --takeover` if an old session holds the lease';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        if ($dryRun) {
            return [];
        }
        $status = (new Git($this->paths->main))->attempt(['status', '--porcelain', '--untracked-files=all', '--', '.gitignore', '.claude', 'CLAUDE.md', 'boost.json'])->out;
        $changed = array_values(array_unique(array_filter(array_map(
            fn (string $line) => preg_replace('#^(\.claude/(?:agents|skills)/).*#', '$1', trim(substr($line, 3), '"')),
            explode("\n", $status),
        ), fn (string $path) => $path !== '' && ! str_starts_with($path, '.claude/worktrees'))));

        return array_values(array_filter([
            'next: review .claude/settings.json (hooks, permissions.allow'.(Bootstrap::rejectsCoAuthored($this->config) ? ', attribution off' : '').'): it applies to everyone who clones the repo',
            $changed === [] ? null : 'next: commit on main: '.implode(' ', $changed),
            'next: on every other machine or fresh clone: composer install, then vendor/bin/kanban attach; check with vendor/bin/kanban doctor',
        ]));
    }

    public function check(): array
    {
        return [];
    }
}
