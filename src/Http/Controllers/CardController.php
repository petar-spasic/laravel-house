<?php

namespace PetarSpasic\Kanban\Http\Controllers;

use Closure;
use Illuminate\Http\Request;
use PetarSpasic\Kanban\Http\Requests\BlockedRequest;
use PetarSpasic\Kanban\Http\Requests\KanbanRequest;
use PetarSpasic\Kanban\Http\Requests\NoteRequest;
use PetarSpasic\Kanban\Http\Requests\PriorityRequest;
use PetarSpasic\Kanban\Http\Requests\StageRequest;
use PetarSpasic\Kanban\Http\Ui;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Markdown;
use Symfony\Component\HttpFoundation\Response;

class CardController
{
    private const LOG_SHOWN = 20;

    public function __construct(
        private readonly Store $store,
        private readonly BoardController $boards,
    ) {}

    public function show(Request $request, string $card): Response
    {
        $data = $this->detail($this->find($card));

        return response()->view($request->boolean('fragment') ? 'kanban::partials.card-detail' : 'kanban::card', $data);
    }

    public function stage(StageRequest $request, string $card): Response
    {
        return $this->write($request, $card, function (Card $card) use ($request) {
            $to = (string) $request->validated('to');
            if (in_array($to, Ui::CLI_ONLY, true) && $to !== $card->stage()) {
                throw new PolicyRefused("CLI only: {$to} is reached through `kanban start`, a worker report or `kanban finish`");
            }
            $moved = (new Transitions($this->store))->move($card->id(), $to, Actor::owner(), $request->validated('reason'), expected: $request->rev());

            return [$moved, "moved to {$to}"];
        });
    }

    public function priority(PriorityRequest $request, string $card): Response
    {
        $priority = (string) $request->validated('priority');

        return $this->write($request, $card, fn (Card $card) => [
            $this->store->update($card->id(), fn (array $data) => ['priority' => $priority] + $data, Actor::owner(), $request->rev()),
            "priority {$priority}",
        ]);
    }

    public function blocked(BlockedRequest $request, string $card): Response
    {
        $reason = trim((string) $request->validated('reason')) ?: null;

        return $this->write($request, $card, fn (Card $card) => [
            $this->store->update($card->id(), fn (array $data) => ['blocked' => $reason] + $data, Actor::owner(), $request->rev()),
            $reason === null ? 'unblocked' : 'blocked',
        ]);
    }

    public function notes(NoteRequest $request, string $card): Response
    {
        $text = trim((string) $request->validated('text'));

        return $this->write($request, $card, fn (Card $card) => [
            $this->store->update($card->id(), function (array $data) use ($text) {
                $data['log'][] = ['event' => 'note', 'text' => $text];

                return $data;
            }, Actor::owner(), $request->rev()),
            'note added',
        ]);
    }

    /**
     * Runs a change against the card the form was rendered from: 409 when it changed since, 422 on a refusal.
     *
     * @param  Closure(Card): array{0: Card, 1: string}  $change
     */
    private function write(KanbanRequest $request, string $id, Closure $change): Response
    {
        $card = $this->find($id);
        try {
            [$updated, $message] = $change($card);
        } catch (Conflict $e) {
            return $this->refuse($request, $this->find($id), $e->getMessage(), [], 409);
        } catch (NotFound $e) {
            abort(404, $e->getMessage());
        } catch (LockTimeout $e) {
            return $this->refuse($request, $card, $e->getMessage(), [], 503);
        } catch (KanbanException $e) {
            return $this->refuse($request, $card, $e->getMessage(), $e->details, 422);
        }

        if ($request->wantsFragment()) {
            return $this->boards->fragment($updated->board);
        }

        return redirect()->back(303, [], route('kanban.board', ['epic' => $updated->board->epic, 'board' => $updated->board->board]))
            ->with('kanban.notice', "{$updated->id()} {$message}");
    }

    /** @param  list<string>  $details */
    private function refuse(KanbanRequest $request, Card $card, string $message, array $details, int $status): Response
    {
        $notice = ['message' => $message, 'details' => $details, 'error' => true];
        if (! $request->wantsFragment()) {
            return response()->view('kanban::card', ['notice' => $notice] + $this->detail($card), $status);
        }
        if ($status === 409) {
            return response()->view('kanban::partials.card-detail', ['notice' => $notice] + $this->detail($card), 409);
        }

        return response()->view('kanban::partials.notice', $notice, $status);
    }

    private function find(string $id): Card
    {
        try {
            return $this->store->card($id);
        } catch (NotFound $e) {
            abort(404, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function detail(Card $card): array
    {
        $snapshot = $this->store->snapshot();
        $board = $snapshot->boardOf($card);

        return [
            'card' => $card,
            'tile' => $this->boards->tile($card, $snapshot, $this->boards->agents($snapshot)),
            'snapshot' => $snapshot,
            'board' => $board,
            'epic' => $snapshot->epic($card->board->epic),
            'targets' => Ui::targets($board?->kind() ?? 'work'),
            'body' => Markdown::render((string) ($card->data['body'] ?? '')),
            'why' => isset($card->data['why']) && $card->data['why'] !== '' ? Markdown::render((string) $card->data['why']) : null,
            'log' => array_slice(array_reverse($card->log()), 0, self::LOG_SHOWN),
            'notice' => null,
        ];
    }
}
