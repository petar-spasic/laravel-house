<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;

final class Snapshot
{
    /**
     * @param  array<string, mixed>  $kanban  kanban.json
     * @param  array<string, Epic>  $epics  slug => epic
     * @param  array<string, Board>  $boards  slug => board
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

    /** The board format this package reads and writes (`version` in kanban.json). */
    public const VERSION = 3;

    /** What every board command, the UI and the hooks say about an older board. */
    public const OLD_BOARD = 'an older board format: the owner runs /implement-kanban';

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
    public const DEFAULT_LOCKED = ['doing', 'review', 'done'];

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

    /**
     * The merge lease (`merge` in kanban.json), or null when no merge holds it.
     *
     * @return array{id: string, card: string, by: string, who?: string, since: string, beat: string, pushing?: string}|null
     */
    public function mergeLease(): ?array
    {
        $lease = $this->kanban['merge'] ?? null;

        return is_array($lease) ? $lease : null;
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

    /** The card's epic, or null when it has none. */
    public function epicOf(Card $card): ?Epic
    {
        return $card->epic() === null ? null : $this->epic($card->epic());
    }

    /** @return list<Epic> ordered by order, slug */
    public function epics(): array
    {
        $epics = array_values($this->epics);
        usort($epics, fn (Epic $a, Epic $b) => [$a->order(), $a->slug] <=> [$b->order(), $b->slug]);

        return $epics;
    }

    /** @return list<Board> ordered by board order, ref */
    public function boards(): array
    {
        $boards = array_values($this->boards);
        usort($boards, fn (Board $a, Board $b) => [$a->order(), (string) $a->ref] <=> [$b->order(), (string) $b->ref]);

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

    public function withBoard(Board $board): self
    {
        return new self($this->kanban, $this->epics, [(string) $board->ref => $board] + $this->boards, $this->cards, $this->problems);
    }

    public function withEpic(Epic $epic): self
    {
        return new self($this->kanban, [$epic->slug => $epic] + $this->epics, $this->boards, $this->cards, $this->problems);
    }

    /** An older format, by kanban.json's own `version`; a file without one is left to validation. */
    public function isOld(): bool
    {
        $version = $this->kanban['version'] ?? null;

        return is_int($version) && $version < self::VERSION;
    }

    /** A dependency is satisfied when its card is done. */
    public function isSatisfied(string $id): bool
    {
        return $this->card($id)?->stage() === 'done';
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

    /** @return array<string, int> stage => count of cards */
    public function stageCounts(): array
    {
        $counts = [];
        foreach ($this->cards as $card) {
            $counts[$card->stage()] = ($counts[$card->stage()] ?? 0) + 1;
        }

        return $counts;
    }
}
