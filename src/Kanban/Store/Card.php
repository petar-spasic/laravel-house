<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

final class Card
{
    /** The prefix of a `blocked` reason that is an open question for the owner. */
    public const QUESTION = 'question: ';

    public const MAX_CRITERIA = 24;

    /** Characters in one acceptance criterion. */
    public const MAX_CRITERION = 500;

    /**
     * @param  array<string, mixed>  $data  the card file, decoded
     * @param  string  $path  relative to the board root
     */
    public function __construct(
        public readonly array $data,
        public readonly BoardRef $board,
        public readonly string $path,
        public readonly Rev $rev,
    ) {}

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function type(): string
    {
        return (string) ($this->data['type'] ?? '');
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? '');
    }

    public function stage(): string
    {
        return (string) ($this->data['stage'] ?? '');
    }

    public function priority(): string
    {
        return (string) ($this->data['priority'] ?? 'normal');
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_values($this->data['labels'] ?? []);
    }

    /** @return list<string> the `area:*` labels, which serialize work */
    public function areas(): array
    {
        return array_values(array_filter($this->labels(), fn (string $label) => str_starts_with($label, 'area:')));
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return array_values($this->data['depends_on'] ?? []);
    }

    public function blocked(): ?string
    {
        return $this->data['blocked'] ?? null;
    }

    /** Whether the card waits on an answer from the owner (`blocked="question: …"`). */
    public function asks(): bool
    {
        return str_starts_with((string) $this->blocked(), self::QUESTION);
    }

    /** @return array{by: string, session: string|null, at: string}|null */
    public function claim(): ?array
    {
        return $this->data['claim'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function work(): ?array
    {
        return $this->data['work'] ?? null;
    }

    /** @return list<array{id: int, text: string, done: bool}> */
    public function acceptance(): array
    {
        return array_values($this->data['acceptance'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function log(): array
    {
        return array_values($this->data['log'] ?? []);
    }

    public function created(): string
    {
        return (string) ($this->data['created'] ?? '');
    }

    public function updated(): string
    {
        return (string) ($this->data['updated'] ?? '');
    }

    /** When the card entered its current stage (from the log), else when it was created. */
    public function stageSince(): string
    {
        foreach (array_reverse($this->log()) as $entry) {
            if (($entry['event'] ?? null) === 'stage' && ($entry['to'] ?? null) === $this->stage()) {
                return (string) $entry['at'];
            }
        }

        return $this->created();
    }

    /** First time the card entered a stage, or null. */
    public function entered(string $stage): ?string
    {
        foreach ($this->log() as $entry) {
            if (($entry['event'] ?? null) === 'stage' && ($entry['to'] ?? null) === $stage) {
                return (string) $entry['at'];
            }
        }

        return null;
    }

    /** Host the card's work runs on (work.host, else the claim's `user@host`), or null. */
    public function host(): ?string
    {
        $host = $this->work()['host'] ?? null;
        if ($host === null && ($by = $this->claim()['by'] ?? null) !== null && str_contains($by, '@')) {
            $host = substr($by, strrpos($by, '@') + 1);
        }

        return $host;
    }
}
