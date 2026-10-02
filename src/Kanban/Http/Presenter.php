<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http;

use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Store\Board;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;
use PetarSpasic\LaravelHouse\Kanban\Support\AgentStates;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Markdown;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/** What the UI script gets: a snapshot of the board as plain arrays. */
final class Presenter
{
    private const DONE_SHOWN = 20;

    private const LOG_SHOWN = 20;

    private const COLLAPSED = ['dropped'];

    /** @var array<string, array{state: string, since: int, beat: int}>|null read on first use: most requests need no agent */
    private ?array $agents = null;

    public function __construct(private readonly Snapshot $snapshot, private readonly Paths $paths) {}

    /** @return array<string, array{state: string, since: int, beat: int}> */
    private function agents(): array
    {
        return $this->agents ??= AgentStates::entries($this->paths, $this->snapshot->staleMinutes());
    }

    /** `stale_after_minutes` from kanban.json, without loading the board. */
    public static function staleMinutes(Paths $paths): int
    {
        $settings = json_decode((string) @file_get_contents($paths->board('kanban.json')), true);

        return Snapshot::staleMinutesOf(is_array($settings) ? $settings : []);
    }

    /** @return list<array<string, mixed>> epics with their boards and the number of cards in each stage */
    public function epics(): array
    {
        $counts = [];
        foreach ($this->snapshot->cards as $card) {
            $counts[(string) $card->board][$card->stage()] = ($counts[(string) $card->board][$card->stage()] ?? 0) + 1;
        }
        $epics = [];
        foreach ($this->snapshot->epics as $slug => $epic) {
            $epics[] = ['slug' => $slug, 'title' => $epic->title(), 'order' => $epic->order(), 'boards' => []];
        }
        usort($epics, fn (array $a, array $b) => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);
        $position = array_flip(array_column($epics, 'slug'));
        foreach ($this->snapshot->boards() as $board) {
            $ref = (string) $board->ref;
            if (! isset($position[$board->ref->epic])) {
                $position[$board->ref->epic] = count($epics);
                $epics[] = ['slug' => $board->ref->epic, 'title' => $board->ref->epic, 'order' => 0, 'boards' => []];
            }
            $epics[$position[$board->ref->epic]]['boards'][] = [
                'ref' => $ref, 'board' => $board->ref->board, 'title' => $board->title(),
                'counts' => array_replace(array_fill_keys(Stage::WORK, 0), $counts[$ref] ?? []),
            ];
        }

        return array_map(fn (array $epic) => array_diff_key($epic, ['order' => 1]), $epics);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function board(BoardRef $ref, bool $all): ?array
    {
        $board = $this->snapshot->board($ref);
        if ($board === null) {
            return null;
        }
        $byStage = [];
        foreach ($this->snapshot->cards as $card) {
            if ($card->board->equals($ref)) {
                $byStage[$card->stage()][] = $card;
            }
        }
        $pull = new PullPolicy;
        $stages = [];
        foreach (Stage::WORK as $stage) {
            $cards = $byStage[$stage] ?? [];
            $total = count($cards);
            if ($stage === 'done') {
                $since = [];
                foreach ($cards as $card) {
                    $since[$card->id()] = $card->stageSince();
                }
                usort($cards, fn (Card $a, Card $b) => [$since[$b->id()], $b->id()] <=> [$since[$a->id()], $a->id()]);
                $cards = $all ? $cards : array_slice($cards, 0, self::DONE_SHOWN);
            } else {
                $cards = $pull->sort($this->snapshot, $cards, $stage);
            }
            $stages[] = [
                'stage' => $stage,
                'total' => $total,
                'limit' => $stage === 'doing' ? $board->wipDoing() : ($stage === 'review' ? ($this->snapshot->kanban['wip']['review'] ?? null) : null),
                'collapsed' => in_array($stage, self::COLLAPSED, true),
                'older' => $total - count($cards),
                'cards' => array_map($this->summary(...), $cards),
            ];
        }

        return [
            'ref' => (string) $ref,
            'title' => $board->title(),
            'epic' => ['slug' => $ref->epic, 'title' => $this->snapshot->epic($ref->epic)?->title() ?? $ref->epic],
            'moves' => Ui::moves(),
            'locked' => $this->snapshot->lockedStages(),
            'stages' => $stages,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Card $card): array
    {
        $deps = $card->dependsOn();
        $acceptance = $card->acceptance();
        // an agent record outlives its card's work; only a card in the work shows it
        $agent = Stage::isActive($card->stage()) ? ($this->agents()[$card->id()] ?? null) : null;

        return [
            'id' => $card->id(),
            'short' => substr($card->id(), (int) strpos($card->id(), '-') + 1),
            'title' => $card->title(),
            'stage' => $card->stage(),
            'priority' => $card->priority(),
            'type' => $card->type(),
            'labels' => $card->labels(),
            'blocked' => $card->blocked(),
            'deps' => ['open' => count(array_filter($deps, fn (string $id) => ! $this->snapshot->isSatisfied($id))), 'total' => count($deps)],
            'progress' => ['done' => count(array_filter($acceptance, fn (array $c) => $c['done'])), 'total' => count($acceptance)],
            'agent' => $agent,
            'url' => Stage::isActive($card->stage()) ? ($card->work()['stack']['url'] ?? null) : null,
            'since' => $this->timestamp($card->stageSince()),
            'rev' => (string) $card->rev,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Card $card): array
    {
        $board = $this->snapshot->boardOf($card);
        $work = $card->work() ?? [];
        $body = (string) ($card->data['body'] ?? '');
        $log = $card->log();
        $fact = fn (string $key) => isset($work[$key]) && is_string($work[$key]) && $work[$key] !== '' ? $work[$key] : null;

        return $this->summary($card) + [
            'body' => $body,
            'body_html' => trim($body) === '' ? '' : Markdown::render($body),
            'acceptance' => $card->acceptance(),
            'depends_on' => array_map(function (string $id) {
                $dep = $this->snapshot->card($id);

                return ['id' => $id, 'title' => $dep?->title() ?? '', 'stage' => $dep?->stage() ?? 'missing', 'satisfied' => $this->snapshot->isSatisfied($id)];
            }, $card->dependsOn()),
            'board' => $this->boardInfo($card, $board),
            'targets' => Ui::moves()[$card->stage()] ?? [],
            'locked' => in_array($card->stage(), $this->snapshot->lockedStages(), true),
            'facts' => [
                'branch' => $fact('branch'),
                'worktree' => $fact('worktree'),
                'merge' => $fact('merge'),
                'parked_branch' => $fact('parked_branch'),
                'claim' => $card->claim(),
                'host' => $card->host(),
                'created' => $card->created(),
                'updated' => $card->updated(),
                'stage_since' => $card->stageSince(),
            ],
            'log' => array_slice(array_reverse($log), 0, self::LOG_SHOWN),
            'log_total' => count($log),
        ];
    }

    /** Seconds since the epoch of a card timestamp; 0 for one a damaged file holds. */
    private function timestamp(string $at): int
    {
        try {
            return Clock::parse($at !== '' ? $at : Clock::now())->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Changes when a board or epic appears, disappears or is renamed: the page reloads its board list then. */
    public function layout(): string
    {
        $parts = [];
        foreach ($this->snapshot->boards() as $board) {
            $parts[] = $board->ref.'|'.$board->title().'|'.($this->snapshot->epic($board->ref->epic)?->title() ?? '');
        }

        return sha1(implode("\n", $parts));
    }

    /**
     * Cards for the dependency picker: ids first, then titles, at most 20; the card asking (`$for`) and what it already
     * depends on are left out.
     *
     * @return list<array<string, string>>
     */
    public function find(string $query, ?string $for = null): array
    {
        $skip = [];
        if ($for !== null && ($asking = $this->snapshot->cards[$for] ?? null) !== null) {
            $skip = array_fill_keys([$asking->id(), ...array_map(fn ($dep) => (string) $dep, $asking->dependsOn())], true);
        }
        $found = [];
        foreach ($this->snapshot->cards as $card) {
            if (isset($skip[$card->id()])) {
                continue;
            }
            $byId = $query === '' || stripos($card->id(), $query) !== false;
            if ($byId || stripos($card->title(), $query) !== false) {
                $found[] = ['rank' => $byId ? 0 : 1, 'id' => $card->id(), 'title' => $card->title(), 'stage' => $card->stage(), 'board' => (string) $card->board];
            }
        }
        usort($found, fn (array $a, array $b) => [$a['rank'], $a['title'], $a['id']] <=> [$b['rank'], $b['title'], $b['id']]);

        return array_map(fn (array $c) => array_diff_key($c, ['rank' => 1]), array_slice($found, 0, 20));
    }

    /**
     * Where the card lives; a card whose board.json is missing still says which board it belongs to.
     *
     * @return array<string, string>
     */
    private function boardInfo(Card $card, ?Board $board): array
    {
        $ref = $card->board;

        return ['ref' => (string) $ref, 'title' => $board?->title() ?? $ref->board,
            'epic' => $this->snapshot->epic($ref->epic)?->title() ?? $ref->epic];
    }
}
