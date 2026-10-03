<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:data-ids')]
class DataIdsCommand extends Command
{
    protected $signature = 'kanban:data-ids
        {--base= : The branch the reference data is compared with (default: main_branch)}
        {--dir=database/data : The reference data directory}
        {--migrations=database/migrations : Where the data-fix migration goes}';

    protected $description = 'Gate: every reference-data id the branch started from is still there, unless the branch adds a migration';

    protected function perform(): int
    {
        $worktrees = new Worktrees($this->paths(), $this->config());
        $card = $worktrees->containing($this->paths()->cwd);
        $card === null || $worktrees->sync($card);
        $git = Git::untrusted($this->paths()->cwd);
        $base = (string) ($this->option('base') ?: $this->setting('main_branch', 'main'));
        $dir = trim((string) $this->option('dir'), '/');
        $fork = trim($git->attempt(['merge-base', $base, 'HEAD'])->out);
        if ($fork === '') {
            $this->fault("no common commit with {$base}");

            return 1;
        }

        $before = $this->ids($git, $fork, $dir);
        $after = $this->ids($git, 'HEAD', $dir);
        $gone = array_diff_key($before, $after);
        if ($gone === []) {
            $this->say('data ids ok: '.count($before).' kept');

            return self::SUCCESS;
        }
        $migrations = trim((string) $this->option('migrations'), '/');
        $added = trim($git->attempt(['diff', '--name-only', '--diff-filter=A', "{$base}...HEAD", '--', $migrations.'/'])->out);
        if ($added !== '') {
            $this->say('data ids: '.count($gone).' removed or renamed, with a migration: '.str_replace("\n", ', ', $added));

            return self::SUCCESS;
        }
        foreach ($gone as $id => $file) {
            $this->fault("{$file}: id {$id} is gone");
        }
        $this->fault("a removed or renamed reference id needs its data-fix migration in {$migrations}/ on the same branch; otherwise keep the id");

        return 1;
    }

    /** @return array<string, string> id => the file that holds it, at $rev */
    private function ids(Git $git, string $rev, string $dir): array
    {
        $ids = [];
        $files = array_filter(explode("\n", trim($git->attempt(['ls-tree', '-r', '--name-only', $rev, '--', $dir.'/'])->out)));
        foreach ($files as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }
            $rows = json_decode($git->attempt(['show', "{$rev}:{$file}"])->out, true);
            $rows = is_array($rows) && ! array_is_list($rows) ? [$rows] : (is_array($rows) ? $rows : []);
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $ids[$row['id']] = $file;
                }
            }
        }

        return $ids;
    }
}
