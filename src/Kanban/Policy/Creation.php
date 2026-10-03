<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\CardType;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use PetarSpasic\LaravelHouse\Kanban\Store\Rev;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * The fields of a new card, from what the CLI (`new`) or the UI was given. The input is checked before an id exists, so a
 * refusal names the caller's field and no card.
 */
final class Creation
{
    /** The names `kanban new` gives the fields. */
    public const CLI = ['title' => 'the title', 'type' => '--type', 'priority' => '--priority', 'epic' => '--epic', 'body' => '--body', 'labels' => '--label', 'accept' => '--accept', 'stage' => '--stage'];

    /** @param  array<string, string>  $names  how the caller names a field in a refusal, by field */
    public function __construct(private readonly array $names = []) {}

    /**
     * @param  array{title: string, type?: ?string, priority?: ?string, epic?: ?string, body?: ?string, labels?: list<string>, accept?: list<string>, depends?: list<string>, stage?: ?string}  $input
     * @return array<string, mixed>
     */
    public function fields(Snapshot $snapshot, BoardRef $ref, array $input): array
    {
        $snapshot->board($ref) ?? throw new NotFound("no board {$ref}");
        $this->check($input);

        $fields = ['title' => $input['title']];
        foreach (['type', 'priority', 'body'] as $field) {
            if (($input[$field] ?? null) !== null) {
                $fields[$field] = $input[$field];
            }
        }
        if (($input['labels'] ?? []) !== []) {
            $fields['labels'] = $input['labels'];
        }
        if (($epic = trim((string) ($input['epic'] ?? ''))) !== '') {
            $snapshot->epic($epic) ?? throw new NotFound($this->name('epic')." {$epic}: no such epic (`vendor/bin/kanban epic {$epic}` creates it)");
            $fields['epic'] = $epic;
        }
        $fields['acceptance'] = $input['accept'] ?? [];
        $fields['depends_on'] = array_map(fn (string $id) => $snapshot->resolve($id)->id(), $input['depends'] ?? []);

        $stage = $input['stage'] ?? null;
        if ($stage !== null && ! in_array($stage, ['backlog', 'ready'], true)) {
            throw new Invalid($this->name('stage').' must be one of: backlog, ready');
        }
        if ($stage === 'ready') {
            $draft = new Card(array_merge(['id' => $snapshot->key().'-DRAFT', 'type' => 'feature', 'stage' => 'backlog', 'blocked' => null, 'body' => ''], $fields), $ref, '', new Rev(''));
            $refusals = (new ReadyPolicy)->refusals($draft, $snapshot, false);
            if ($refusals !== []) {
                throw new PolicyRefused('refused: '.implode('; ', $refusals), $refusals);
            }
            $fields['stage'] = 'ready';
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $input */
    private function check(array $input): void
    {
        $title = (string) $input['title'];
        if (trim($title) === '') {
            throw new Invalid($this->name('title').' is blank');
        }
        if (mb_strlen($title) > 120) {
            throw new Invalid($this->name('title').' is '.mb_strlen($title).' characters; the limit is 120');
        }
        foreach (['type' => CardType::values(), 'priority' => array_column(Priority::cases(), 'value')] as $field => $allowed) {
            if (($input[$field] ?? null) !== null && ! in_array($input[$field], $allowed, true)) {
                throw new Invalid($this->name($field)." '{$input[$field]}' is not one of: ".implode(', ', $allowed));
            }
        }
        if (mb_strlen((string) ($input['body'] ?? '')) > Card::MAX_BODY) {
            throw new Invalid($this->name('body').' is '.mb_strlen((string) $input['body']).' characters; the limit is '.Card::MAX_BODY);
        }
        if (count($input['labels'] ?? []) > Card::MAX_LABELS) {
            throw new Invalid($this->name('labels').': at most '.Card::MAX_LABELS.' labels');
        }
        $accept = $input['accept'] ?? [];
        if (count($accept) > Card::MAX_CRITERIA) {
            throw new Invalid($this->name('accept').': at most '.Card::MAX_CRITERIA.' acceptance criteria');
        }
        foreach ($accept as $i => $criterion) {
            if (trim($criterion) === '') {
                throw new Invalid($this->name('accept').' '.($i + 1).' is blank');
            }
            if (mb_strlen($criterion) > Card::MAX_CRITERION) {
                throw new Invalid($this->name('accept').' '.($i + 1).' is '.mb_strlen($criterion).' characters; an acceptance criterion has at most '.Card::MAX_CRITERION.' characters');
            }
        }
    }

    private function name(string $field): string
    {
        return $this->names[$field] ?? $field;
    }
}
