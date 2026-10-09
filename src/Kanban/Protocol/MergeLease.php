<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Policy\MergeQueue;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Claim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Waiting;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/**
 * The merge lease: one merge at a time for the whole project, as `merge` in kanban.json, written only through
 * Store::lease (won by the push that lands, like a claim). This checkout's side of it is MergeState.
 *
 * Expiry never compares clocks across machines: a lease is expired once this machine has seen the same (id, beat) for
 * EXPIRE_SECONDS on its monotonic clock. Those observations are `merge-seen.json`, under `merge.lock`:
 * `{"boot": <boot_id>, "lease": {"id", "beat", "seen"}, "heads": {<card id>: {"since"}}}`, `seen` and `since` in seconds
 * of hrtime(true), the whole file reset when /proc/sys/kernel/random/boot_id changes or the clock is behind a time it
 * holds (a reboot where there is no boot_id). `heads` holds the foreign cards
 * this machine's queue walk stopped at (observe()), each since it first stopped there.
 *
 * Callers that may clear MergeState or take an older lease of this checkout as their own hold `merge.run.lock`.
 */
final class MergeLease
{
    /** How often the beater beats. */
    public const HEARTBEAT_SECONDS = 120;

    /** How long a lease's (id, beat), or a foreign card at the front of the queue, stands still before it is passed over. */
    public const EXPIRE_SECONDS = 900;

    private readonly MergeState $state;

    public function __construct(
        private readonly Store $store,
        private readonly Paths $paths,
        private readonly Actor $by,
    ) {
        $this->state = new MergeState($paths);
    }

    /**
     * Takes the lease for $card: free, this checkout's own from an earlier try, or expired as observed here. $eligible
     * gets the snapshot the lease is taken on (origin's when sync is on) and throws to refuse. When the lease taken over
     * was pushing a merge, $landed says whether origin's main holds it (asked before the board lock, only once the lease
     * is expired here: MainPush::landed);
     * when it does, its card moves to done in the takeover's own commit, which may be $card itself, so the caller reads
     * the card again. MergeState is `merging`, round 1, after it; it stays `acquiring` when the remote failed or the
     * board may hold the id (the caller releases it), and is cleared on a refusal of a new id (Waiting when the lease is
     * held).
     *
     * @param  Closure(Snapshot): void  $eligible
     * @param  (Closure(string): bool)|null  $landed
     * @return array<string, mixed> the lease
     */
    public function acquire(Card $card, Closure $eligible, ?Closure $landed = null): array
    {
        $prior = $this->state->read();
        if ($prior !== null && $prior['card'] !== $card->id()) {
            throw new Waiting("merging {$prior['card']} here");
        }
        $settled = null;
        $held = $this->store->snapshot()->mergeLease();
        if ($landed !== null && isset($held['pushing']) && $held['id'] !== ($prior['lease'] ?? null) && $this->expired($held)) {
            $settled = [$held['id'], $held['beat'], $held['pushing'], $landed($held['pushing'])];
        }
        $id = $prior['lease'] ?? bin2hex(random_bytes(8));
        $this->state->start($card->id(), $id);
        $decided = false;
        $decide = function (?array $old, Snapshot $snapshot) use ($card, $eligible, $id, $settled, &$decided) {
            $decided = false;
            $over = $old !== null && $old['id'] !== $id ? $old : null;
            if ($over !== null && ! $this->expired($over)) {
                throw new Waiting('merge lease '.self::describe($over));
            }
            $eligible($snapshot);
            $now = Clock::now();
            $lease = ['id' => $id, 'card' => $card->id(), 'by' => Claim::here($this->by)->by,
                'since' => $over === null && $old !== null ? $old['since'] : $now, 'beat' => $now];
            $message = "merge lease {$card->id()} ".($over === null ? 'taken' : "taken over from {$over['by']}");
            $cards = [];
            $done = $over === null ? null : $snapshot->card($over['card']);
            if ($done?->stage() === 'review' && $settled === [$over['id'], $over['beat'], $over['pushing'] ?? null, true]) {
                $cards[$done->id()] = fn (array $data) => Transitions::finished($data, $over['pushing']);
                $message .= "; {$done->id()} done (its push landed)";
            }
            $decided = true;

            return [$lease, $cards, $message];
        };
        try {
            $lease = $this->store->lease($decide, $this->by);
        } catch (RemoteFailed $e) {
            // a push that failed may have landed all the same: the next acquire reuses this id and knows the lease as its own
            throw $e;
        } catch (Throwable $e) {
            // the board may hold an id an earlier try or a push of this one took: it stays for the caller to give back
            if ($prior === null && ! $decided) {
                $this->state->clear($id);
            }

            throw $e;
        }
        $this->state->set(['phase' => MergeState::MERGING, 'round' => 1, 'merger_rounds' => 0, 'merger_runs' => 0, 'beat_at' => Clock::now()], $id);

        return (array) $lease;
    }

    /**
     * Moves the lease's beat. A fence beat sets its `pushing` to $pushing (none: removed), and blocks and throws; the
     * beater's beat keeps `pushing` (adding `merged` while the phase is pushing), is skipped on a busy board lock and
     * pushes for at most 5 s, a remote failure costing nothing before the lease expires. LostClaim when the lease is no
     * longer this checkout's (the beater's beat records it as `lost`).
     */
    public function beat(?string $pushing = null, bool $fence = false): void
    {
        $state = $this->state->read();
        if ($state === null || $state['phase'] === MergeState::RELEASING) {
            if ($fence) {
                throw new LostClaim('merge lease: no merge holds it here');
            }

            return;
        }
        $lease = $state['lease'];
        $pushing ??= ! $fence && $state['phase'] === MergeState::PUSHING ? ($state['merged'] ?? null) : null;
        $decide = function (?array $old) use ($lease, $pushing, $fence, $state) {
            if (($old['id'] ?? null) !== $lease) {
                throw new LostClaim($old === null ? 'merge lease lost: the board holds none' : 'merge lease lost: now '.self::describe($old));
            }
            if ($fence) {
                unset($old['pushing']);
            }

            return [array_replace($old, ['beat' => Clock::now()], $pushing === null ? [] : ['pushing' => $pushing]), [], "merge lease {$state['card']} beat"];
        };
        if ($fence) {
            $this->store->lease($decide, $this->by);
            $this->state->set(['beat_at' => Clock::now()], $lease);

            return;
        }
        try {
            if ($this->store->lease($decide, $this->by, true, 5) !== null) {
                $this->state->set(['beat_at' => Clock::now()], $lease);
            }
        } catch (LostClaim $e) {
            $this->state->set(['lost' => $e->getMessage()], $lease);

            throw $e;
        }
    }

    /** Gives the lease back; MergeState is cleared once that landed, or the lease is no longer this checkout's. */
    public function release(): void
    {
        $state = $this->state->read();
        if ($state === null) {
            return;
        }
        $lease = $state['lease'];
        $this->state->set(['phase' => MergeState::RELEASING], $lease);
        $this->store->lease(fn (?array $old) => ($old['id'] ?? null) === $lease
            ? [null, [], "merge lease {$state['card']} released"]
            : [$old, [], ''], $this->by);
        $this->state->clear($lease);
    }

    /**
     * What this machine sees of the lease and the front of the queue: this machine's next card and why no other (the
     * walk of MergeQueue::next), how long the lease's (id, beat) stood still here (null when none is held), whether that
     * makes it expired, and the foreign cards it may pass over. A foreign card where the walk stops is timed, and is
     * passed over once it stood there for EXPIRE_SECONDS and no unexpired lease names it.
     *
     * @param  Closure(Card): bool  $local
     * @param  Closure(Card): ?string  $ready
     * @return array{card: ?Card, why: string, expired: bool, stood: ?float, skippable: list<string>}
     */
    public function observe(Snapshot $snapshot, ?string $originMain, Closure $local, Closure $ready): array
    {
        return $this->seen(function (array &$seen, float $now) use ($snapshot, $originMain, $local, $ready) {
            $lease = $snapshot->mergeLease();
            $stood = $lease === null ? null : $this->stood($seen, $lease, $now);
            $expired = $stood !== null && $stood >= self::EXPIRE_SECONDS;
            $heads = [];
            $skippable = [];
            $next = MergeQueue::next($snapshot, $originMain, $local, $ready, function (Card $card) use (&$heads, &$skippable, $seen, $now, $lease, $expired) {
                $heads[$card->id()] = ['since' => $since = (float) ($seen['heads'][$card->id()]['since'] ?? $now)];
                if ($now - $since < self::EXPIRE_SECONDS || (($lease['card'] ?? null) === $card->id() && ! $expired)) {
                    return false;
                }
                $skippable[] = $card->id();

                return true;
            });
            $seen['heads'] = $heads;

            return $next + ['expired' => $expired, 'stood' => $stood, 'skippable' => $skippable];
        });
    }

    /** Whether the board's lease is this checkout's. */
    public function mine(Snapshot $snapshot): bool
    {
        $state = $this->state->read();

        return $state !== null && ($snapshot->mergeLease()['id'] ?? null) === $state['lease'];
    }

    /**
     * `held by ana@host-a (Ana) for ACME-7K2QF9 since <since>`.
     *
     * @param  array<string, mixed>  $lease
     */
    public static function describe(array $lease): string
    {
        return "held by {$lease['by']}".(isset($lease['who']) ? " ({$lease['who']})" : '')." for {$lease['card']} since {$lease['since']}";
    }

    /** @param  array<string, mixed>  $lease */
    private function expired(array $lease): bool
    {
        return $this->seen(fn (array &$seen, float $now) => $this->stood($seen, $lease, $now)) >= self::EXPIRE_SECONDS;
    }

    /**
     * Seconds this machine has seen $lease's (id, beat) unchanged; starts counting when they changed.
     *
     * @param  array<string, mixed>  $seen
     * @param  array<string, mixed>  $lease
     */
    private function stood(array &$seen, array $lease, float $now): float
    {
        if (($seen['lease']['id'] ?? null) !== $lease['id'] || ($seen['lease']['beat'] ?? null) !== $lease['beat']) {
            $seen['lease'] = ['id' => $lease['id'], 'beat' => $lease['beat'], 'seen' => $now];
        }

        return $now - (float) $seen['lease']['seen'];
    }

    /** Runs $fn on merge-seen.json (reset after a reboot) and the monotonic time in seconds, and saves what it changed. */
    private function seen(Closure $fn): mixed
    {
        $lock = Lock::exclusive($this->state->lockFile(), 5);
        try {
            $file = $this->paths->runtime('merge-seen.json');
            $boot = trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
            $seen = Runtime::readJson($file) ?? [];
            $now = hrtime(true) / 1e9;
            // a monotonic clock behind what it recorded restarted: a reboot where no boot_id tells
            $recorded = [(float) ($seen['lease']['seen'] ?? 0), ...array_map(fn ($h) => (float) ($h['since'] ?? 0), (array) ($seen['heads'] ?? []))];
            if (($seen['boot'] ?? null) !== $boot || max($recorded) > $now) {
                $seen = ['boot' => $boot];
            }
            $result = $fn($seen, $now);
            Runtime::writeJson($file, $seen);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
