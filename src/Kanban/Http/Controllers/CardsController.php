<?php

namespace PetarSpasic\Kanban\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PetarSpasic\Kanban\Http\Api;
use PetarSpasic\Kanban\Http\Presenter;
use PetarSpasic\Kanban\Http\Ui;
use PetarSpasic\Kanban\Policy\Creation;
use PetarSpasic\Kanban\Policy\Edits;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\CardType;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Priority;
use PetarSpasic\Kanban\Store\Rev;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Store\Stage;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Paths;
use Symfony\Component\HttpFoundation\Response;

class CardsController
{
    private const LABEL = ['string', 'max:60', 'regex:/^[a-z0-9]+([-:][a-z0-9]+)*$/'];

    public function __construct(private readonly Store $store, private readonly Paths $paths) {}

    public function index(Request $request): Response
    {
        $query = $request->query('q');
        $for = $request->query('for');

        return Api::run(fn () => Api::json(['cards' => $this->present($this->store->snapshot())->find(is_string($query) ? trim($query) : '', is_string($for) ? $for : null)]));
    }

    public function show(string $card): Response
    {
        return Api::run(function () use ($card) {
            $snapshot = $this->store->snapshot();
            $found = $snapshot->resolve($card);

            return Api::json(['card' => $this->present($snapshot)->detail($found)]);
        });
    }

    public function store(Request $request, string $epic, string $board): JsonResponse
    {
        return Api::run(function () use ($request, $epic, $board) {
            $input = $request->validate($this->fieldRules(new: true) + [
                'acceptance' => ['array', 'max:12'], 'acceptance.*' => ['required', 'string', 'max:300'],
                'stage' => ['nullable', Rule::in(self::stages())],
            ]);
            $ref = new BoardRef($epic, $board);
            $snapshot = $this->store->snapshot();
            $fields = (new Creation)->fields($snapshot, $ref, [
                'title' => trim($input['title']),
                'type' => $input['type'] ?? null,
                'priority' => $input['priority'] ?? null,
                'why' => $input['why'] ?? null,
                'body' => $input['body'] ?? null,
                'labels' => $input['labels'] ?? [],
                'accept' => array_map('trim', $input['acceptance'] ?? []),
                'depends' => $this->dependencies($snapshot, $input['depends_on'] ?? []),
                'stage' => $input['stage'] ?? null,
            ]);
            $card = $this->store->create($ref, $fields, Actor::owner());

            return Api::json(['card' => $this->present($snapshot->withCard($card))->detail($card)], 201);
        });
    }

    public function patch(Request $request, string $card): JsonResponse
    {
        return $this->write($request, $card, $this->fieldRules(new: false) + [
            'blocked' => ['nullable', 'string', 'max:500'],
            'acceptance' => ['array'],
            'acceptance.*.id' => ['nullable', 'integer', 'min:1'],
            'acceptance.*.text' => ['required', 'string', 'max:300'],
            'acceptance.*.done' => ['boolean'],
        ], function (Card $card, Snapshot $snapshot, array $input, Rev $rev) {
            $dependsOn = array_key_exists('depends_on', $input) ? $this->dependencies($snapshot, $input['depends_on'] ?? []) : null;
            $locked = $snapshot->lockedStages();

            return $this->store->update($card->id(), function (array $data) use ($input, $dependsOn, $locked) {
                $before = $data;
                $removed = [];
                foreach (['type', 'priority'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $data[$field] = $input[$field];
                    }
                }
                if (array_key_exists('title', $input)) {
                    $data['title'] = trim($input['title']);
                }
                foreach (['body', 'why'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $data[$field] = (string) $input[$field];
                    }
                }
                if (array_key_exists('labels', $input)) {
                    $data['labels'] = array_values(array_unique($input['labels'] ?? []));
                }
                if (array_key_exists('blocked', $input)) {
                    $reason = trim((string) $input['blocked']);
                    $data['blocked'] = $reason === '' ? null : $reason;
                }
                if ($dependsOn !== null) {
                    $data['depends_on'] = $dependsOn;
                }
                if (array_key_exists('acceptance', $input)) {
                    $data = Edits::replaceAcceptance($data, array_map(fn (array $item) => [...$item, 'text' => trim($item['text'])], $input['acceptance'] ?? []), $removed);
                }
                Edits::assertOpen($before, $data, $locked);

                return Edits::recordRemoved($before, $data, $removed);
            }, Actor::owner(), $rev);
        });
    }

    public function stage(Request $request, string $card): JsonResponse
    {
        return $this->write($request, $card, [
            'to' => ['required', Rule::in(self::stages())],
            'reason' => ['nullable', 'string', 'max:500'],
        ], function (Card $card, Snapshot $snapshot, array $input, Rev $rev) {
            $to = $input['to'];
            if ($to === $card->stage()) {
                return $card;
            }
            if (in_array($to, Ui::CLI_ONLY, true)) {
                throw new PolicyRefused("CLI only: {$to} is reached through `kanban start`, a worker report or `kanban finish`");
            }

            return (new Transitions($this->store))->move($card->id(), $to, Actor::owner(), $input['reason'] ?? null, expected: $rev);
        });
    }

    public function notes(Request $request, string $card): JsonResponse
    {
        return $this->write($request, $card, ['text' => ['required', 'string', 'max:5000']],
            fn (Card $card, Snapshot $snapshot, array $input, Rev $rev) => $this->store->update(
                $card->id(), fn (array $data) => Edits::note($data, trim($input['text'])), Actor::owner(), $rev));
    }

    /**
     * A change to the card the page rendered: 409 when it changed since (the answer carries the fresh card).
     *
     * @param  array<string, mixed>  $rules
     * @param  Closure(Card, Snapshot, array<string, mixed>, Rev): Card  $change
     */
    private function write(Request $request, string $id, array $rules, Closure $change): JsonResponse
    {
        return Api::run(function () use ($request, $id, $rules, $change) {
            $input = $request->validate($rules + ['rev' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/']]);
            $snapshot = $this->store->snapshot();
            $card = $snapshot->resolve($id);
            $updated = $change($card, $snapshot, $input, new Rev($input['rev']));

            return Api::json(['card' => $this->present($snapshot->withCard($updated))->detail($updated)]);
        }, function () use ($id) {
            $snapshot = $this->store->snapshot();

            return ['card' => $this->present($snapshot)->detail($snapshot->resolve($id))];
        });
    }

    /**
     * @param  list<string>  $ids  card ids or unique prefixes
     * @return list<string>
     */
    private function dependencies(Snapshot $snapshot, array $ids): array
    {
        return array_values(array_unique(array_map(function (string $id) use ($snapshot) {
            try {
                return $snapshot->resolve($id)->id();
            } catch (NotFound $e) {
                throw new Invalid('cannot depend on '.$e->getMessage());
            }
        }, $ids)));
    }

    /**
     * The rules of the fields a card has when it is made and when it is changed (the store's schema is the last word).
     *
     * @return array<string, mixed>
     */
    private function fieldRules(bool $new): array
    {
        return [
            'title' => [$new ? 'required' : 'filled', 'string', 'max:120'],
            'type' => [$new ? 'nullable' : 'sometimes', Rule::in(array_column(CardType::cases(), 'value'))],
            'priority' => [$new ? 'nullable' : 'sometimes', Rule::in(array_column(Priority::cases(), 'value'))],
            'labels' => ['array', 'max:10'], 'labels.*' => self::LABEL,
            'body' => ['nullable', 'string', 'max:20000'],
            'why' => ['nullable', 'string', 'max:10000'],
            'depends_on' => ['array'], 'depends_on.*' => ['string'],
        ];
    }

    /** @return list<string> */
    private static function stages(): array
    {
        return array_map(fn (Stage $stage) => $stage->value, Stage::cases());
    }

    private function present(Snapshot $snapshot): Presenter
    {
        return new Presenter($snapshot, $this->paths);
    }
}
