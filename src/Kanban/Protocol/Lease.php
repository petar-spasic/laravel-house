<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * One orchestrating session per machine: `lease.json` = {session, since, transcript?}, its mtime the last use. Main-only
 * commands call acquire(); a lease idle for 15 minutes is free; `kanban lease --takeover` takes a fresh one, and
 * SessionStart hands it to a new session of the holder's own transcript (KANBAN_TRANSCRIPT).
 */
final class Lease
{
    public const IDLE_SECONDS = 900;

    public function __construct(private readonly Paths $paths) {}

    /**
     * The current holder, or null when free or expired.
     *
     * @return array{session: string, since: string, idle: int, transcript: ?string, from: ?string}|null
     */
    public function holder(): ?array
    {
        $file = $this->paths->leaseFile();
        $lease = Runtime::readJson($file);
        $idle = time() - (int) @filemtime($file);
        if ($lease === null || ! is_string($lease['session'] ?? null) || $lease['session'] === '' || $idle > self::IDLE_SECONDS) {
            return null;
        }

        return [
            'session' => $lease['session'],
            'since' => (string) ($lease['since'] ?? ''),
            'idle' => $idle,
            'transcript' => is_string($lease['transcript'] ?? null) ? $lease['transcript'] : null,
            'from' => is_string($lease['from'] ?? null) ? $lease['from'] : null,
        ];
    }

    /**
     * A new session of the holder's own transcript (after /compact or a restart) takes the lease over; returns the
     * previous session when it did.
     */
    public function handover(string $session, string $transcript): ?string
    {
        $lock = Lock::exclusive($this->paths->ensureRuntime().'/lease.lock', 5);
        try {
            $holder = $this->holder();
            if ($holder === null || $holder['session'] === $session || $holder['transcript'] !== $transcript) {
                return null;
            }
            Runtime::writeJson($this->paths->leaseFile(), ['session' => $session, 'since' => Clock::now(), 'transcript' => $transcript, 'from' => $holder['session']]);

            return $holder['session'];
        } finally {
            $lock->release();
        }
    }

    /** Takes or refreshes the lease for a main session; exit 6 when another session holds a fresh one. No-op for the owner. */
    public function acquire(Actor $actor): void
    {
        if (! $actor->isMain()) {
            return;
        }
        $this->write($actor->session, false);
    }

    public function takeover(Actor $actor): ?array
    {
        if (! $actor->isMain()) {
            throw new PolicyRefused('lease --takeover is for a main session (KANBAN_SESSION set)');
        }

        return $this->write($actor->session, true);
    }

    /** Frees the lease when this session holds it. */
    public function release(Actor $actor): bool
    {
        $holder = $this->holder();
        if ($holder === null || $holder['session'] !== $actor->session) {
            return false;
        }
        @unlink($this->paths->leaseFile());

        return true;
    }

    /** `this session`, `free`, or `held by <session> (idle 3m)`, saying when the holder is another transcript. */
    public function describe(?string $session, ?string $transcript = null): string
    {
        $holder = $this->holder();
        $transcript ??= self::transcript();

        return match (true) {
            $holder === null => 'free',
            $holder['session'] === $session => 'this session'.($holder['from'] !== null ? " (taken over from {$holder['from']}, same transcript)" : ''),
            default => 'held by '.$holder['session'].' (idle '.Clock::human($holder['idle']).')'
                .($transcript !== null && $holder['transcript'] !== null && $holder['transcript'] !== $transcript ? ', another transcript; `vendor/bin/kanban lease --takeover` only when that session is gone' : ''),
        };
    }

    private static function transcript(): ?string
    {
        $transcript = getenv('KANBAN_TRANSCRIPT');

        return is_string($transcript) && $transcript !== '' ? $transcript : null;
    }

    /** @return array{session: string, since: string, idle: int, transcript: ?string, from: ?string}|null the previous holder when taken over */
    private function write(string $session, bool $takeover): ?array
    {
        $lock = Lock::exclusive($this->paths->ensureRuntime().'/lease.lock', 5);
        try {
            $holder = $this->holder();
            if ($holder !== null && $holder['session'] !== $session && ! $takeover) {
                throw new LockTimeout("another session holds the orchestrator lease ({$holder['session']}, idle ".Clock::human($holder['idle']).'); wait 15 min idle or run `vendor/bin/kanban lease --takeover`');
            }
            $since = $holder !== null && $holder['session'] === $session ? $holder['since'] : Clock::now();
            $transcript = self::transcript() ?? ($holder !== null && $holder['session'] === $session ? $holder['transcript'] : null);
            Runtime::writeJson($this->paths->leaseFile(), array_filter(['session' => $session, 'since' => $since, 'transcript' => $transcript], fn ($v) => $v !== null));

            return $holder !== null && $holder['session'] !== $session ? $holder : null;
        } finally {
            $lock->release();
        }
    }
}
