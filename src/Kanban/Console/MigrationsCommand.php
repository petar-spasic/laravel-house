<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use DateTimeImmutable;
use DateTimeZone;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:migrations')]
class MigrationsCommand extends Command
{
    protected $signature = 'kanban:migrations
        {--base= : The branch the migrations must be newer than (default: main_branch)}
        {--dir=database/migrations : The migrations directory}';

    protected $description = 'Gate: the migrations this branch adds carry make:migration timestamps (real, not round, not shared, not ahead of the clock, newer than the base)';

    protected function perform(): int
    {
        $worktrees = new Worktrees($this->paths(), $this->config());
        $card = $worktrees->containing($this->paths()->cwd);
        $card === null || $worktrees->sync($card);
        $git = Git::untrusted($this->paths()->cwd);
        $base = (string) ($this->option('base') ?: $this->setting('main_branch', 'main'));
        $dir = trim((string) $this->option('dir'), '/');
        $names = fn (array $args) => array_values(array_filter(explode("\n", trim($git->attempt($args)->out))));

        $added = $names(['diff', '--name-only', '--diff-filter=A', "{$base}...HEAD", '--', $dir.'/']);
        $onBase = $names(['ls-tree', '-r', '--name-only', $base, '--', $dir.'/']);
        $theirs = array_filter(array_map(self::stamp(...), $onBase));
        $newest = $theirs === [] ? '' : max($theirs);
        // The base's files too: a migration main gained after the branch forked is not in HEAD yet.
        $all = array_count_values(array_filter(array_map(self::stamp(...), array_unique([...$onBase, ...$names(['ls-tree', '-r', '--name-only', 'HEAD', '--', $dir.'/'])]))));

        // make:migration stamps in the app's timezone, which this command never reads: the latest any app can be ahead
        // of UTC is UTC+14, and five minutes more cover a container's clock running behind.
        $latest = gmdate('Y_m_d_His', time() + 14 * 3600 + 300);

        $problems = [];
        foreach ($added as $file) {
            if (($stamp = self::stamp($file)) === null) {
                continue;
            }
            $why = match (true) {
                ! self::isDateTime($stamp) => 'is not a date and time, a typed timestamp',
                $stamp > $latest => 'is ahead of the clock, a typed timestamp',
                str_ends_with($stamp, '0000') => 'ends in 0000, a typed timestamp',
                ($all[$stamp] ?? 0) > 1 => 'is shared with another migration',
                $stamp <= $newest => "is not newer than {$base}'s newest migration ({$newest})",
                default => null,
            };
            if ($why !== null) {
                $problems[] = "{$file}: timestamp {$stamp} {$why}";
            }
        }
        if ($problems === []) {
            $this->say('migrations ok: '.count($added).' added');

            return self::SUCCESS;
        }
        foreach ($problems as $problem) {
            $this->fault($problem);
        }
        $this->fault('regenerate each with `php artisan make:migration`, move its body into the new file and delete the old one');

        return 1;
    }

    /** `2026_01_31_142233` of `…/2026_01_31_142233_create_notes_table.php`, or null for any other file name. */
    private static function stamp(string $file): ?string
    {
        return preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_\w+\.php$/', basename($file), $m) === 1 ? $m[1] : null;
    }

    private static function isDateTime(string $stamp): bool
    {
        $at = DateTimeImmutable::createFromFormat('!Y_m_d_His', $stamp, new DateTimeZone('UTC'));

        return $at !== false && $at->format('Y_m_d_His') === $stamp;
    }
}
