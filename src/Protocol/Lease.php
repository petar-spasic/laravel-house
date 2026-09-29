<?php

namespace PetarSpasic\Kanban\Protocol;

use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Lock;
use PetarSpasic\Kanban\Support\Paths;

/**
 * One orchestrating session per machine: `lease.json` = {session, since}, its mtime the last use. Main-only commands
 * call acquire(); a lease idle for 15 minutes is free; `kanban lease --takeover` takes a fresh one.
 */
final class Lease
{
    public const IDLE_SECONDS = 900;

    public function __construct(private readonly Paths $paths) {}

    /**
     * The current holder, or null when free or expired.
     *
     * @return array{session: string, since: string, idle: int}|null
     */
    public function holder(): ?array
    {
        $file = $this->paths->leaseFile();
        $lease = Runtime::readJson($file);
        $idle = time() - (int) @filemtime($file);
        if ($lease === null || ! is_string($lease['session'] ?? null) || $lease['session'] === '' || $idle > self::IDLE_SECONDS) {
            return null;
        }

        return ['session' => $lease['session'], 'since' => (string) ($lease['since'] ?? ''), 'idle' => $idle];
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

    /** `this session`, `free`, or `held by <session> (idle 3m)`. */
    public function describe(?string $session): string
    {
        $holder = $this->holder();

        return match (true) {
            $holder === null => 'free',
            $holder['session'] === $session => 'this session',
            default => 'held by '.$holder['session'].' (idle '.Clock::human($holder['idle']).')',
        };
    }

    /** @return array{session: string, since: string, idle: int}|null the previous holder when taken over */
    private function write(string $session, bool $takeover): ?array
    {
        $lock = Lock::exclusive($this->paths->ensureRuntime().'/lease.lock', 5);
        try {
            $holder = $this->holder();
            if ($holder !== null && $holder['session'] !== $session && ! $takeover) {
                throw new LockTimeout("another session holds the orchestrator lease ({$holder['session']}, idle ".Clock::human($holder['idle']).'); wait 15 min idle or run `vendor/bin/kanban lease --takeover`');
            }
            $since = $holder !== null && $holder['session'] === $session ? $holder['since'] : Clock::now();
            Runtime::writeJson($this->paths->leaseFile(), ['session' => $session, 'since' => $since]);

            return $holder !== null && $holder['session'] !== $session ? $holder : null;
        } finally {
            $lock->release();
        }
    }
}
