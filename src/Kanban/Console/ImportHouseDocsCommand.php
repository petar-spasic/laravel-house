<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Import\DecisionsMarkdown;
use PetarSpasic\Kanban\Import\IdeasMarkdown;
use PetarSpasic\Kanban\Schema\CrossCardRules;
use PetarSpasic\Kanban\Schema\Validator;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Rev;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Ids;
use PetarSpasic\Kanban\Support\Json;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The house docs (`docs/decisions.md`, `docs/ideas.md`) as decision cards, in one commit. Idempotent: a card
 * remembers the sha1 of its source line, and a line already imported is skipped.
 *
 * @phpstan-import-type Entry from DecisionsMarkdown
 */
#[AsCommand(name: 'kanban:import-house-docs')]
class ImportHouseDocsCommand extends Command
{
    protected $signature = 'kanban:import-house-docs
        {--decisions= : Decisions file, relative to the main checkout (default docs/decisions.md)}
        {--ideas= : Ideas file, relative to the main checkout (default docs/ideas.md)}
        {--board=project/decisions : Target board (kind decisions)}
        {--dry-run : Print what would be imported; write nothing}
        {--strict : Exit 2 when any line cannot be parsed}';

    protected $description = 'Import docs/decisions.md and docs/ideas.md as decision cards';

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
        $this->duplicates($decisions[1]['entries'] ?? [], $ideas[1]['entries'] ?? [], $decisions[0] ?? '', $ideas[0] ?? '');

        $ref = BoardRef::parse((string) $this->option('board'));
        $snapshot = $this->store()->snapshot();
        $board = $snapshot->board($ref);
        if ($board !== null && $board->kind() !== 'decisions') {
            throw new PolicyRefused("{$ref} is a {$board->kind()} board; import needs a decisions board");
        }

        if ($this->option('dry-run')) {
            if ($board === null) {
                $this->say("would create board {$ref} (kind decisions)");
            }
            $new = $this->fresh($entries, $this->importedHashes($snapshot));
            foreach ($new as $entry) {
                $this->say("{$entry['file']}:{$entry['line']} {$entry['stage']} {$entry['date']} {$entry['title']}");
                $this->supersession($entry, "{$entry['file']}:{$entry['line']}");
            }
            $this->totals('would import', $new, count($entries) - count($new));

            return self::SUCCESS;
        }

        if ($board === null) {
            $this->store()->saveBoard($ref, ['kind' => 'decisions'], $this->actor());
            $this->say("created board {$ref}");
        }
        $created = $this->import($ref, $entries);
        foreach ($created as [$card, $entry]) {
            $this->say("created {$card->id()} {$card->stage()} {$entry['file']}:{$entry['line']} {$card->title()}");
        }
        foreach ($created as [$card, $entry]) {
            $this->supersession($entry, $card->id());
        }
        $this->totals('imported', array_column($created, 1), count($entries) - count($created));

        return self::SUCCESS;
    }

    /**
     * Writes every new card and commits them together, under the board's write lock.
     *
     * @param  list<Entry&array{file: string}>  $entries
     * @return list<array{0: Card, 1: Entry&array{file: string}}>
     */
    private function import(BoardRef $ref, array $entries): array
    {
        $store = $this->gitStore() ?? throw new PolicyRefused('import-house-docs needs the git store');

        return $store->write(function () use ($store, $ref, $entries) {
            if ($store->repo()->git() === null) {
                throw new GitFailed('git is unusable in the board worktree; nothing imported');
            }
            $snapshot = $store->snapshot();
            $taken = $snapshot->cards + $store->repo()->remoteIds();
            $before = $snapshot;
            $now = Clock::now();
            $created = [];
            $errors = [];
            $validator = new Validator;
            foreach ($this->fresh($entries, $this->importedHashes($snapshot)) as $entry) {
                $id = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6), fn (string $id) => isset($taken[$id]));
                $taken[$id] = true;
                $at = $entry['date'].'T00:00:00.000+00:00';
                $data = Json::canonical([
                    'id' => $id, 'type' => 'decision', 'title' => $entry['title'], 'stage' => $entry['stage'], 'priority' => 'normal',
                    'labels' => [], 'body' => $entry['body'], 'why' => $entry['why'], 'decided_on' => $entry['decided_on'],
                    'supersedes' => [], 'superseded_by' => null, 'resolution' => $entry['resolution'],
                    'source' => ['file' => $entry['file'], 'line' => $entry['line'], 'hash' => sha1($entry['raw'])],
                    'created' => $at, 'updated' => $now,
                    'log' => [['id' => Ids::log(), 'at' => $at, 'by' => 'import', 'event' => 'created']],
                ], 'card');
                $card = new Card($data, $ref, "{$ref}/{$id}.json", Rev::of(Json::encode($data, 'card')));
                foreach ($validator->validate('card', $data) as $error) {
                    $errors[] = "{$entry['file']}:{$entry['line']}: {$error}";
                }
                $snapshot = $snapshot->withCard($card);
                $created[] = [$card, $entry];
            }
            $rules = new CrossCardRules;
            $errors = array_merge($errors, array_values(array_diff($rules->check($snapshot), $rules->check($before))));
            if ($errors !== []) {
                throw new Invalid('invalid: '.$errors[0].'; nothing imported', $errors);
            }
            if ($created === []) {
                return [];
            }
            foreach ($created as [$card]) {
                Json::write($this->paths()->board($card->path), Json::encode($card->data, 'card'));
            }
            $message = 'Kanban: import house docs ('.count($created).' cards) ['.$this->actor()->role.']';
            if (! $store->repo()->commit(array_map(fn (array $pair) => $pair[0]->path, $created), $message)) {
                throw new GitFailed('the imported cards were written but could not be committed');
            }

            return $created;
        });
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

    /** @return array<string, true> source hashes of every card (a moved card stays imported) */
    private function importedHashes(Snapshot $snapshot): array
    {
        $hashes = [];
        foreach ($snapshot->cards as $card) {
            if (isset($card->data['source']['hash'])) {
                $hashes[$card->data['source']['hash']] = true;
            }
        }

        return $hashes;
    }

    /**
     * Entries whose source line is not imported yet (a line repeated in the docs counts once).
     *
     * @param  list<Entry&array{file: string}>  $entries
     * @param  array<string, true>  $hashes
     * @return list<Entry&array{file: string}>
     */
    private function fresh(array $entries, array $hashes): array
    {
        $fresh = [];
        foreach ($entries as $entry) {
            $hash = sha1($entry['raw']);
            if (! isset($hashes[$hash])) {
                $hashes[$hash] = true;
                $fresh[] = $entry;
            }
        }

        return $fresh;
    }

    /** @param  Entry  $entry */
    private function supersession(array $entry, string $card): void
    {
        if ($entry['supersession']) {
            $this->say("warning: {$entry['file']}:{$entry['line']} mentions a supersession; link it by hand: kanban set {$card} supersedes=+<ID>");
        }
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

    /** @param  list<array{stage: string, file: string}>  $entries */
    private function totals(string $verb, array $entries, int $skipped): void
    {
        $stages = array_count_values(array_column($entries, 'stage')) + ['decided' => 0, 'proposed' => 0, 'dropped' => 0];
        $files = array_count_values(array_column($entries, 'file'));
        $from = implode(', ', array_map(fn (string $file, int $n) => "{$n} from {$file}", array_keys($files), $files));
        $this->say("{$verb} ".count($entries).": {$stages['decided']} decided, {$stages['proposed']} proposed, {$stages['dropped']} dropped"
            .($from !== '' ? " ({$from})" : '')."; {$skipped} already imported");
    }
}
