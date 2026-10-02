<?php

namespace PetarSpasic\Kanban\Policy;

use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Rev;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Support\Clock;

/** The fields of a new card, from what the CLI (`new`) or the UI was given. */
final class Creation
{
    /** @param  string  $stageName  how the caller names the option in a refusal (`--stage` on the command line) */
    public function __construct(private readonly string $stageName = 'stage') {}

    /**
     * @param  array{title: string, type?: ?string, priority?: ?string, why?: ?string, decided_on?: ?string, body?: ?string, labels?: list<string>, accept?: list<string>, depends?: list<string>, stage?: ?string}  $input
     * @return array<string, mixed>
     */
    public function fields(Snapshot $snapshot, BoardRef $ref, array $input): array
    {
        $board = $snapshot->board($ref) ?? throw new NotFound("no board {$ref}");
        $decisions = $board->kind() === 'decisions';

        $fields = ['title' => $input['title']];
        foreach (['type', 'priority', 'why', 'decided_on', 'body'] as $field) {
            if (($input[$field] ?? null) !== null) {
                $fields[$field] = $input[$field];
            }
        }
        if (($input['labels'] ?? []) !== []) {
            $fields['labels'] = $input['labels'];
        }
        $accept = $input['accept'] ?? [];
        $depends = $input['depends'] ?? [];
        if (! $decisions) {
            $fields['acceptance'] = $accept;
            $fields['depends_on'] = array_map(fn (string $id) => $snapshot->resolve($id)->id(), $depends);
        } elseif ($accept !== [] || $depends !== []) {
            throw new Invalid('decisions have no acceptance criteria or dependencies');
        }

        $stage = $input['stage'] ?? null;
        $allowed = $decisions ? ['proposed', 'decided'] : ['backlog', 'ready'];
        if ($stage !== null && ! in_array($stage, $allowed, true)) {
            throw new Invalid($this->stageName.' must be one of: '.implode(', ', $allowed));
        }
        if ($stage === 'decided') {
            $fields['stage'] = 'decided';
            $fields['decided_on'] ??= Clock::today();
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
