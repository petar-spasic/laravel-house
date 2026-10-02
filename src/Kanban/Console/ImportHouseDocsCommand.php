<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Import\DecisionsMarkdown;
use PetarSpasic\LaravelHouse\Kanban\Import\IdeasMarkdown;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Changes;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Archive;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The house docs (`docs/decisions.md`, `docs/ideas.md`) on the board, in one commit: what was decided or dropped goes
 * to the archive (`decisions.md` on the board branch), and an idea still floated becomes a backlog spike whose open
 * question is its block. Idempotent: an archive section's id is derived from its source line, and a spike's `created`
 * log entry keeps the line's sha1.
 *
 * @phpstan-import-type Entry from DecisionsMarkdown
 */
#[AsCommand(name: 'kanban:import-house-docs')]
class ImportHouseDocsCommand extends Command
{
    protected $signature = 'kanban:import-house-docs
        {--decisions= : Decisions file, relative to the main checkout (default docs/decisions.md)}
        {--ideas= : Ideas file, relative to the main checkout (default docs/ideas.md)}
        {--dry-run : Print what would be imported; write nothing}
        {--strict : Exit 2 when any line cannot be parsed}';

    protected $description = 'Import docs/decisions.md and docs/ideas.md: decisions to the archive, open ideas as backlog spikes';

    private const STOPWORDS = ['the', 'and', 'for', 'not', 'are', 'with', 'from', 'its', 'but', 'that', 'this', 'one', 'all'];

    protected function perform(): int
    {
        $this->requireMainOrOwner('import-house-docs');
        [$decisions, $ideas] = [$this->read('decisions'), $this->read('ideas')];
        if ($decisions === null && $ideas === null) {
            $this->say('nothing to import (no docs/decisions.md or docs/ideas.md)');

            return self::SUCCESS;
        }
        foreach (['decisions' => $decisions, 'ideas' => $ideas] as $which => $read) {
            if ($read === null) {
                $this->say("skipped docs/{$which}.md: not found");
            }
        }

        /** @var list<Entry&array{file: string}> $entries */
        $entries = [];
        $unparsed = [];
        foreach (array_filter([$decisions, $ideas]) as [$file, $parsed]) {
            foreach ($parsed['warnings'] as $warning) {
                $this->say("warning: {$file}:{$warning['line']} {$warning['message']}");
                if ($warning['unparsed']) {
                    $unparsed[] = "{$file}:{$warning['line']}";
                }
            }
            foreach ($parsed['entries'] as $entry) {
                $entries[] = $entry + ['file' => $file];
            }
        }
        if ($unparsed !== [] && $this->option('strict')) {
            throw new Invalid('strict: '.count($unparsed).' line(s) could not be parsed; nothing imported', $unparsed);
        }
        $this->assertDates($entries);
        $this->duplicates($decisions[1]['entries'] ?? [], $ideas[1]['entries'] ?? [], $decisions[0] ?? '', $ideas[0] ?? '');

        $store = $this->gitStore() ?? throw new PolicyRefused('import-house-docs needs the git store');
        $snapshot = $store->snapshot();
        $board = ($snapshot->boards()[0] ?? null)?->ref ?? throw new NotFound('no board to import onto');
        [$archive, $spikes] = $this->fresh($entries, $snapshot, $store->archive());

        if ($this->option('dry-run')) {
            foreach ($archive as $entry) {
                $this->say("{$entry['file']}:{$entry['line']} {$entry['stage']} {$entry['date']} {$entry['title']} → archive ".$this->archiveId($entry, $snapshot));
            }
            foreach ($spikes as $entry) {
                $this->say("{$entry['file']}:{$entry['line']} {$entry['stage']} {$entry['date']} {$entry['title']} → backlog spike on {$board}");
            }
            $this->totals('would import', $archive, $spikes, count($entries) - count($archive) - count($spikes));

            return self::SUCCESS;
        }
        if ($archive === [] && $spikes === []) {
            $this->totals('imported', [], [], count($entries));

            return self::SUCCESS;
        }

        $created = $store->batch(function (Snapshot $snapshot) use ($store, $entries, $board, &$archive, &$spikes) {
            [$archive, $spikes] = $this->fresh($entries, $snapshot, $current = $store->archive());
            $changes = new Changes;
            foreach ($spikes as $entry) {
                $changes->create($board, $this->spike($entry));
            }
            $sections = array_map(fn (array $entry) => $this->section($entry, $snapshot), $archive);
            if ($sections !== []) {
                $changes->text(Archive::PATH, (string) Archive::add($current, $sections));
            }

            return $changes;
        }, $this->actor(), 'Kanban: import house docs ('.count($archive).' archived, '.count($spikes).' spikes)');

        foreach ($archive as $entry) {
            $this->say('archived '.$this->archiveId($entry, $snapshot)." {$entry['stage']} {$entry['file']}:{$entry['line']} {$entry['title']}");
        }
        foreach (array_values($created) as $i => $card) {
            $this->say("created {$card->id()} {$card->stage()} {$spikes[$i]['file']}:{$spikes[$i]['line']} {$card->title()}");
        }
        $this->totals('imported', $archive, $spikes, count($entries) - count($archive) - count($spikes));

        return self::SUCCESS;
    }

    /**
     * An idea still floated, as the fields of a backlog spike: the idea under `## Open question`, the question as the
     * block, created on its date.
     *
     * @param  Entry&array{file: string}  $entry
     * @return array<string, mixed>
     */
    private function spike(array $entry): array
    {
        $at = $entry['date'].'T00:00:00.000+00:00';
        $text = implode("\n\n", array_filter([trim($entry['body']), trim($entry['why']) === '' ? '' : 'Why: '.trim($entry['why']),
            "Floated on {$entry['date']} ({$entry['file']}:{$entry['line']})."]));

        return [
            'type' => 'spike', 'title' => $entry['title'], 'body' => "## Open question\n\n{$text}", 'blocked' => Card::QUESTION.$entry['title'],
            'created' => $at,
            'log' => [['id' => Ids::log(), 'at' => $at, 'by' => 'import', 'event' => 'created', 'source' => "{$entry['file']}:{$entry['line']}", 'hash' => sha1($entry['raw'])]],
        ];
    }

    /**
     * @param  Entry&array{file: string}  $entry
     * @return array{id: string, title: string, date: string, facts: list<string>, body: string, why: string, resolution: string|null}
     */
    private function section(array $entry, Snapshot $snapshot): array
    {
        return [
            'id' => $this->archiveId($entry, $snapshot), 'title' => $entry['title'], 'date' => $entry['decided_on'] ?? $entry['date'],
            'facts' => [$entry['stage'], "{$entry['file']}:{$entry['line']}"], 'body' => $entry['body'], 'why' => $entry['why'],
            'resolution' => $entry['resolution'],
        ];
    }

    /** @param  Entry  $entry */
    private function archiveId(array $entry, Snapshot $snapshot): string
    {
        return $snapshot->key().'-'.substr(Ids::derived('import '.sha1($entry['raw'])), 0, min(8, max(4, (int) $snapshot->setting('id_length', 6))));
    }

    /**
     * What is not on the board yet: decided and dropped entries without an archive section, floated ones without a
     * spike (a line repeated in the docs counts once).
     *
     * @param  list<Entry&array{file: string}>  $entries
     * @return array{0: list<Entry&array{file: string}>, 1: list<Entry&array{file: string}>}
     */
    private function fresh(array $entries, Snapshot $snapshot, ?string $archive): array
    {
        $done = Archive::ids($archive);
        foreach ($snapshot->cards as $card) {
            foreach ($card->log() as $entry) {
                if (isset($entry['hash'])) {
                    $done[$entry['hash']] = true;
                }
            }
        }
        $toArchive = [];
        $spikes = [];
        foreach ($entries as $entry) {
            $key = $entry['stage'] === 'proposed' ? sha1($entry['raw']) : $this->archiveId($entry, $snapshot);
            if (isset($done[$key])) {
                continue;
            }
            $done[$key] = true;
            if ($entry['stage'] === 'proposed') {
                $spikes[] = $entry;
            } else {
                $toArchive[] = $entry;
            }
        }

        return [$toArchive, $spikes];
    }

    /** @param  list<Entry&array{file: string}>  $entries */
    private function assertDates(array $entries): void
    {
        $errors = [];
        foreach ($entries as $entry) {
            foreach (array_unique(array_filter([$entry['date'], $entry['decided_on']])) as $date) {
                [$y, $m, $d] = array_map('intval', explode('-', $date));
                if (! checkdate($m, $d, $y)) {
                    $errors[] = "{$entry['file']}:{$entry['line']}: {$date} is not a date on the calendar";
                }
            }
        }
        if ($errors !== []) {
            throw new Invalid('invalid: '.$errors[0].'; nothing imported', $errors);
        }
    }

    /**
     * The parsed file as [display path, parse result], or null when a default file is missing.
     *
     * @return array{0: string, 1: array{entries: list<Entry>, warnings: list<array{line: int, message: string, unparsed: bool}>}}|null
     */
    private function read(string $which): ?array
    {
        $given = $this->option($which);
        $file = $given ?? "docs/{$which}.md";
        $absolute = str_starts_with($file, '/') ? $file : $this->paths()->main.'/'.$file;
        if (! is_file($absolute)) {
            if ($given !== null) {
                throw new NotFound("cannot read {$file}");
            }

            return null;
        }
        $markdown = (string) file_get_contents($absolute);
        $display = $this->paths()->relative($absolute);

        return [$display, $which === 'decisions' ? DecisionsMarkdown::parse($markdown) : IdeasMarkdown::parse($markdown)];
    }

    /**
     * Ideas that share a date with a decision and at least half of the shorter title's words. Printed, never merged.
     *
     * @param  list<Entry>  $decisions
     * @param  list<Entry>  $ideas
     */
    private function duplicates(array $decisions, array $ideas, string $decisionsFile, string $ideasFile): void
    {
        foreach ($ideas as $idea) {
            foreach ($decisions as $decision) {
                if ($idea['date'] !== $decision['date']) {
                    continue;
                }
                [$a, $b] = [$this->words($idea['title']), $this->words($decision['title'])];
                $shorter = min(count($a), count($b));
                if ($shorter > 0 && count(array_intersect($a, $b)) / $shorter >= 0.5) {
                    $this->say("possible duplicate: {$ideasFile}:{$idea['line']} \"{$idea['title']}\" ~ {$decisionsFile}:{$decision['line']} \"{$decision['title']}\"");
                }
            }
        }
    }

    /** @return list<string> */
    private function words(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }

    /**
     * @param  list<array{stage: string, file: string}>  $archived
     * @param  list<array{stage: string, file: string}>  $spikes
     */
    private function totals(string $verb, array $archived, array $spikes, int $skipped): void
    {
        $stages = array_count_values(array_column($archived, 'stage')) + ['decided' => 0, 'dropped' => 0];
        $files = array_count_values(array_column([...$archived, ...$spikes], 'file'));
        $from = implode(', ', array_map(fn (string $file, int $n) => "{$n} from {$file}", array_keys($files), $files));
        $this->say("{$verb} ".(count($archived) + count($spikes)).': '.count($archived)." archived ({$stages['decided']} decided, {$stages['dropped']} dropped), "
            .count($spikes).' backlog spikes with an open question'.($from !== '' ? " ({$from})" : '')."; {$skipped} already imported");
    }
}
