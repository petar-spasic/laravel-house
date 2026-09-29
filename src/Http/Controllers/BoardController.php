<?php

namespace PetarSpasic\Kanban\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PetarSpasic\Kanban\Http\Ui;
use PetarSpasic\Kanban\Policy\PullPolicy;
use PetarSpasic\Kanban\Store\Board;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Epic;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Store\Stage;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\AgentStates;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Paths;

class BoardController
{
    private const DONE_SHOWN = 20;

    private const COLLAPSED = ['dropped', 'superseded'];

    public function __construct(private readonly Store $store, private readonly Paths $paths) {}

    public function index(): View
    {
        if (! $this->paths->hasBoard()) {
            return view('kanban::index', ['epics' => [], 'notices' => [
                'No board at docs/kanban on this machine: run `vendor/bin/kanban attach` (or `php artisan kanban:install` in a new project).',
            ]]);
        }
        $snapshot = $this->store->snapshot();
        $notices = [];
        if (($pending = $this->store->pending()) > 0) {
            $notices[] = "{$pending} UI ".($pending === 1 ? 'write is' : 'writes are').' saved but not committed yet: the next CLI write or `kanban sync` commits them.';
        }
        if ($snapshot->problems !== []) {
            $notices[] = count($snapshot->problems).' board files have problems: run `vendor/bin/kanban validate`.';
        }
        $epics = $snapshot->epics;
        uasort($epics, fn (Epic $a, Epic $b) => [$a->order(), $a->slug] <=> [$b->order(), $b->slug]);
        $groups = [];
        foreach ($epics as $slug => $epic) {
            $boards = array_filter($snapshot->boards(), fn (Board $b) => $b->ref->epic === $slug);
            $groups[] = ['epic' => $epic, 'boards' => array_map(fn (Board $board) => [
                'board' => $board,
                'counts' => $this->counts($snapshot, $board),
            ], array_values($boards))];
        }

        return view('kanban::index', ['epics' => $groups, 'notices' => $notices, 'key' => $snapshot->key()]);
    }

    public function show(string $epic, string $board): View
    {
        return view('kanban::board', $this->board(new BoardRef($epic, $board)));
    }

    public function columns(Request $request, string $epic, string $board): Response
    {
        $ref = new BoardRef($epic, $board);
        $etag = $this->etag();
        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, ['ETag' => $etag]);
        }

        return $this->fragment($ref, $etag);
    }

    /** The columns fragment of a board, as swapped in by the page script. */
    public function fragment(BoardRef $ref, ?string $etag = null): Response
    {
        $etag ??= $this->etag();

        return response(view('kanban::board', $this->board($ref, $etag))->fragment('columns'), 200, ['ETag' => $etag]);
    }

    /** Weak ETag: the board files' fingerprint plus the runtime agent heartbeats (worker state on tiles). */
    public function etag(): string
    {
        $agents = [];
        foreach (glob($this->paths->agents().'/*.json') ?: [] as $file) {
            $agents[] = basename($file).':'.@filemtime($file);
        }

        return 'W/"'.sha1($this->store->fingerprint().'|'.implode(',', $agents)).'"';
    }

    /** @return array<string, mixed> */
    public function board(BoardRef $ref, ?string $etag = null): array
    {
        $snapshot = $this->store->snapshot();
        $board = $snapshot->board($ref) ?? abort(404);
        $agents = $this->agents($snapshot);
        $pull = new PullPolicy;
        $columns = [];
        foreach (Stage::forKind($board->kind()) as $stage) {
            $cards = $snapshot->cards(fn (Card $c) => $c->board->equals($ref) && $c->stage() === $stage);
            $total = count($cards);
            if ($stage === 'done') {
                $since = array_combine(array_map(fn (Card $c) => $c->id(), $cards), array_map(fn (Card $c) => $c->stageSince(), $cards));
                usort($cards, fn (Card $a, Card $b) => [$since[$b->id()], $b->id()] <=> [$since[$a->id()], $a->id()]);
                $cards = array_slice($cards, 0, self::DONE_SHOWN);
            } else {
                $cards = $pull->sort($snapshot, $cards, $stage);
            }
            $columns[] = [
                'stage' => $stage,
                'total' => $total,
                'limit' => $stage === 'doing' ? $board->wipDoing() : ($stage === 'review' ? ($snapshot->kanban['wip']['review'] ?? null) : null),
                'collapsed' => in_array($stage, self::COLLAPSED, true),
                'tiles' => array_map(fn (Card $c) => $this->tile($c, $snapshot, $agents), $cards),
            ];
        }

        return [
            'snapshot' => $snapshot,
            'board' => $board,
            'epic' => $snapshot->epic($ref->epic),
            'columns' => $columns,
            'targets' => Ui::targets($board->kind()),
            'etag' => $etag ?? $this->etag(),
        ];
    }

    /**
     * @param  array<string, string>  $agents
     * @return array<string, mixed>
     */
    public function tile(Card $card, Snapshot $snapshot, array $agents): array
    {
        return [
            'card' => $card,
            'short' => substr($card->id(), (int) strpos($card->id(), '-') + 1),
            'age' => Clock::human(Clock::seconds($card->stageSince() ?: Clock::now())),
            'deps_open' => count(array_filter($card->dependsOn(), fn (string $id) => ! $snapshot->isSatisfied($id))),
            'agent' => $agents[$card->id()] ?? null,
            'url' => Stage::isActive($card->stage()) ? ($card->work()['stack']['url'] ?? null) : null,
        ];
    }

    /** @return array<string, string> card id => `stopped`, `stale 25m` or `working 4m` */
    public function agents(Snapshot $snapshot): array
    {
        return array_map(
            fn (string $state) => AgentStates::isWorking($state) ? 'working '.$state : $state,
            AgentStates::byCard($this->paths, (int) $snapshot->setting('stale_after_minutes', 20)),
        );
    }

    /** @return array<string, int> stage => cards on the board */
    private function counts(Snapshot $snapshot, Board $board): array
    {
        $counts = array_fill_keys(Stage::forKind($board->kind()), 0);
        foreach ($snapshot->cards(fn (Card $c) => $c->board->equals($board->ref)) as $card) {
            $counts[$card->stage()] = ($counts[$card->stage()] ?? 0) + 1;
        }

        return $counts;
    }
}
