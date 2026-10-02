<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Rev;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/** The fields of a new card, from what the CLI (`new`) or the UI was given. */
final class Creation
{
    /** @param  string  $stageName  how the caller names the option in a refusal (`--stage` on the command line) */
    public function __construct(private readonly string $stageName = 'stage') {}

    /**
     * @param  array{title: string, type?: ?string, priority?: ?string, body?: ?string, labels?: list<string>, accept?: list<string>, depends?: list<string>, stage?: ?string}  $input
     * @return array<string, mixed>
     */
    public function fields(Snapshot $snapshot, BoardRef $ref, array $input): array
    {
        $snapshot->board($ref) ?? throw new NotFound("no board {$ref}");

        $fields = ['title' => $input['title']];
        foreach (['type', 'priority', 'body'] as $field) {
            if (($input[$field] ?? null) !== null) {
                $fields[$field] = $input[$field];
            }
        }
        if (($input['labels'] ?? []) !== []) {
            $fields['labels'] = $input['labels'];
        }
        $accept = $input['accept'] ?? [];
        if (count($accept) > Card::MAX_CRITERIA) {
            throw new Invalid('at most '.Card::MAX_CRITERIA.' acceptance criteria');
        }
        foreach ($accept as $criterion) {
            if (mb_strlen($criterion) > Card::MAX_CRITERION) {
                throw new Invalid('an acceptance criterion has at most '.Card::MAX_CRITERION.' characters');
            }
        }
        $fields['acceptance'] = $accept;
        $fields['depends_on'] = array_map(fn (string $id) => $snapshot->resolve($id)->id(), $input['depends'] ?? []);

        $stage = $input['stage'] ?? null;
        if ($stage !== null && ! in_array($stage, ['backlog', 'ready'], true)) {
            throw new Invalid($this->stageName.' must be one of: backlog, ready');
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
}
