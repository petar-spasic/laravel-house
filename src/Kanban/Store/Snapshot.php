<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;

final class Snapshot
{
    /**
     * @param  array<string, mixed>  $kanban  kanban.json
     * @param  array<string, Epic>  $epics  slug => epic
     * @param  array<string, Board>  $boards  "epic/board" => board
     * @param  array<string, Card>  $cards  id => card
     * @param  array<string, list<string>>  $problems  relative path => load problems (unreadable, duplicate id, misplaced)
     */
    public function __construct(
        public readonly array $kanban,
        public readonly array $epics,
        public readonly array $boards,
        public readonly array $cards,
        public readonly array $problems = [],
    ) {}

    public const DEFAULT_STALE_MINUTES = 20;

    /** Minutes without a heartbeat before an agent counts as stale. */
    public function staleMinutes(): int
    {
        return self::staleMinutesOf($this->kanban);
    }

    /** @param  array<string, mixed>  $kanban  kanban.json */
    public static function staleMinutesOf(array $kanban): int
    {
        return max(1, (int) ($kanban['stale_after_minutes'] ?? self::DEFAULT_STALE_MINUTES));
    }

    /** Stages whose cards the owner and the main session can only annotate (a note, a blocked reason, ticks), unless kanban.json lists others. */
    public const DEFAULT_LOCKED = ['doing', 'review', 'done', 'superseded'];

    /** @return list<string> */
    public function lockedStages(): array
    {
        return self::lockedOf($this->kanban);
    }

    /**
     * @param  array<string, mixed>  $kanban  kanban.json
     * @return list<string>
     */
    public static function lockedOf(array $kanban): array
    {
        $locked = $kanban['locked'] ?? self::DEFAULT_LOCKED;

        return is_array($locked) ? array_values(array_filter($locked, 'is_string')) : self::DEFAULT_LOCKED;
    }

    public function key(): string
    {
        return (string) ($this->kanban['key'] ?? 'KAN');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->kanban;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function card(string $id): ?Card
    {
        return $this->cards[$id] ?? null;
    }

    /** Resolves a full id or a unique prefix of ≥ 3 characters after the (optional) key. */
    public function resolve(string $input): Card
    {
        $normalized = Ids::normalize($input);
        if (isset($this->cards[$normalized])) {
            return $this->cards[$normalized];
        }
        [$key, $suffix] = str_contains($normalized, '-') ? explode('-', $normalized, 2) : [null, $normalized];
        if (strlen($suffix) < 3) {
            throw new NotFound("'{$input}': give at least 3 characters of the id");
        }
        $matches = array_filter($this->cards, function (Card $card) use ($key, $suffix) {
            [$cardKey, $cardSuffix] = explode('-', $card->id(), 2) + [1 => ''];

            return ($key === null || $key === $cardKey) && str_starts_with($cardSuffix, $suffix);
        });
        if (count($matches) !== 1) {
            throw new NotFound(count($matches) === 0 ? "no card '{$input}'" : "'{$input}' is ambiguous: ".implode(', ', array_keys($matches)));
        }

        return reset($matches);
    }

    /** @return list<Card> */
    public function cards(?callable $filter = null): array
    {
        return array_values($filter === null ? $this->cards : array_filter($this->cards, $filter));
    }

    public function board(BoardRef|string $ref): ?Board
    {
        return $this->boards[(string) $ref] ?? null;
    }

    public function boardOf(Card $card): ?Board
    {
        return $this->board($card->board);
    }

    public function epic(string $slug): ?Epic
    {
        return $this->epics[$slug] ?? null;
    }

    /** @return list<Board> ordered by epic order, board order, ref */
    public function boards(): array
    {
        $boards = array_values($this->boards);
        usort($boards, fn (Board $a, Board $b) => [$this->epic($a->ref->epic)?->order() ?? 0, $a->order(), (string) $a->ref]
            <=> [$this->epic($b->ref->epic)?->order() ?? 0, $b->order(), (string) $b->ref]);

        return $boards;
    }

    public function withCard(Card $card): self
    {
        return new self($this->kanban, $this->epics, $this->boards, [$card->id() => $card] + $this->cards, $this->problems);
    }

    public function withoutCard(string $id): self
    {
        $cards = $this->cards;
        unset($cards[$id]);

        return new self($this->kanban, $this->epics, $this->boards, $cards, $this->problems);
    }

    public function withBoard(Board $board, ?Epic $epic = null): self
    {
        $epics = $epic === null ? $this->epics : [$epic->slug => $epic] + $this->epics;

        return new self($this->kanban, $epics, [(string) $board->ref => $board] + $this->boards, $this->cards, $this->problems);
    }

    /** A dependency is satisfied when its work card is done or its decision is decided. */
    public function isSatisfied(string $id): bool
    {
        $dep = $this->card($id);

        return $dep !== null && in_array($dep->stage(), ['done', 'decided'], true);
    }

    public function depsSatisfied(Card $card): bool
    {
        foreach ($card->dependsOn() as $id) {
            if (! $this->isSatisfied($id)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> stage => count of cards on work boards */
    public function stageCounts(): array
    {
        $counts = [];
        foreach ($this->cards as $card) {
            $counts[$card->stage()] = ($counts[$card->stage()] ?? 0) + 1;
        }

        return $counts;
    }
}
