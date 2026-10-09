<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * This checkout's merge in flight: `.git/laravel-house/merge.json`, read and written under `merge.lock`; absent when no
 * merge runs here. It is cleared or an older one reclaimed only by the process holding `merge.run.lock` (the one
 * `finish` of this checkout), so no live merge loses it.
 *
 * Fields:
 * - `card`: the card being merged.
 * - `lease`: the id of the merge lease taken for it. The board's lease is this checkout's when its `id` is this, never
 *   by host or session.
 * - `phase`: one of PHASES.
 * - `round`: from 1. A rejected push, or a clone whose history was rewritten, starts the next round on a new base.
 * - `merger_rounds`: merger results applied (resolved, fixed). A red after the third sends the card back.
 * - `merger_runs`: merger launches since the last result applied; the third that ends with none blocks the card.
 * - `base`, `head`: the round's pins, `refs/merge-queue/<card>/base` (origin's main as fetched, else local main) and
 *   `…/card` (`work.approved.head`).
 * - `merge_commit`: the merge of the two pins (computed in main), or the merger's conflict resolution.
 * - `merged`: what is pushed to main: `merge_commit` plus the merger's fixes. Set from the phase `pushing` on.
 * - `checked`: the merge clone's HEAD the checks ran on: only it is pushed.
 * - `replay`: {from, to}, the merger's fixes of an earlier round, cherry-picked onto the next round's merge before its checks.
 * - `on_main`: the commands that a merger found failing on main too, for a card filed for main red: they no longer stop it.
 * - `conflicts`: the conflicted paths (phase `conflict`).
 * - `failure`: {step, command, exit, tail, base_rerun} of the failed check (phase `red`); `step` is install, gate or
 *   suite, `base_rerun` passed, failed (a command the merged tree lost, red on the base) or skipped.
 * - `lost`: why the lease was lost, written by the beater; `finish` stops at once and exits 8.
 * - `started`: when the merge began; `beat_at`: when the last beat landed (Clock::now, for people).
 *
 * The merge's history is in the card's log, one `merge` entry per outcome (entry() builds them; RESULTS lists each
 * result's fields), written `by: main` from `finish` and `by: merger` from an applied merger result:
 * - `conflict`: the merge stopped on conflicts, the merger is launched.
 * - `red`: a gate, an install or the suite failed on the merged tree and code could not call it main's.
 * - `main`: main is red: the base rerun failed too (main), or the merger judged so (merger). `red` names the main-red
 *   card (never the card merged), and MergeQueue holds the queue while origin's main is `base`. A card filed for main
 *   red goes on with its checks (`on_main`); any other leaves the queue.
 * - `resolved`, `fixed`: the merger's result applied.
 * - `back`: the merger, the round cap or leftover conflict markers sent the card back, in the same write as its `stage`
 *   entry via `merge`.
 * - `stale`: the branch head left the approval, which is cleared.
 * - `landed`: the card's code reached main (`merge`) without that push's own run moving the card to done.
 * A staged merger result for a merge that is over is logged as `merge_moot` (moot()). A merge ends in the `stage` entry
 * to done via `finish`, with `work.merge`.
 */
final class MergeState
{
    /** The lease id is drawn and its push may not have landed: the next acquire reuses the id and knows it as its own. */
    public const ACQUIRING = 'acquiring';

    /** The lease is held; the round's merge is being made. */
    public const MERGING = 'merging';

    /** The merge stopped on conflicts: the merger's turn. */
    public const CONFLICT = 'conflict';

    /** The merged tree is being checked in the merge stack: installs, gates, the suite. */
    public const CHECKS = 'checks';

    /** A check failed: the merger's turn. */
    public const RED = 'red';

    /** `merged` is fenced on the lease (`pushing`) and pushed: never aborted, settled by asking origin. */
    public const PUSHING = 'pushing';

    /** The merger's `back`, or `main` for a card not filed for main red, was applied: the next `finish` releases the lease and exits 13. */
    public const RELEASED = 'released';

    /** The lease is being given back; kept until a release lands, retried by every `finish` and `kanban run` pass. */
    public const RELEASING = 'releasing';

    public const PHASES = [self::ACQUIRING, self::MERGING, self::CONFLICT, self::CHECKS, self::RED, self::PUSHING, self::RELEASED, self::RELEASING];

    /** What a merger may stage (`kanban merged ID <outcome>`). */
    public const OUTCOMES = ['resolved', 'fixed', 'back', 'main'];

    /** `merge` log entry result => [required fields, optional fields]. */
    public const RESULTS = [
        'conflict' => [['files', 'base', 'round'], []],
        'red' => [['step', 'command', 'exit', 'base', 'round', 'base_rerun'], []],
        'main' => [['red', 'command', 'base'], ['hash', 'note']],
        'resolved' => [['head', 'hash', 'note'], []],
        'fixed' => [['head', 'hash', 'note'], []],
        'back' => [['note'], ['hash', 'files', 'command']],
        'stale' => [['head'], []],
        'landed' => [['merge'], []],
    ];

    private const FIELDS = ['card', 'lease', 'phase', 'round', 'merger_rounds', 'merger_runs', 'base', 'head', 'merge_commit',
        'merged', 'checked', 'replay', 'conflicts', 'failure', 'on_main', 'lost', 'started', 'beat_at'];

    public function __construct(private readonly Paths $paths) {}

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        return $this->locked(fn () => Runtime::readJson($this->file()));
    }

    /**
     * A new merge of $card under lease $lease, still acquiring.
     *
     * @return array<string, mixed>
     */
    public function start(string $card, string $lease): array
    {
        $state = ['card' => $card, 'lease' => $lease, 'phase' => self::ACQUIRING, 'started' => Clock::now()];
        $this->locked(fn () => $this->write($state));

        return $state;
    }

    /**
     * Sets $fields (null removes one) on the merge in flight, only while it is still lease $lease when one is given;
     * the state after it, or null when there is none (then nothing is written).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     */
    public function set(array $fields, ?string $lease = null): ?array
    {
        return $this->update(fn (array $state) => array_filter(array_replace($state, $fields), fn ($v) => $v !== null), $lease);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>|null
     */
    public function update(Closure $change, ?string $lease = null): ?array
    {
        return $this->locked(function () use ($change, $lease) {
            $state = Runtime::readJson($this->file());
            if ($state === null || ($lease !== null && ($state['lease'] ?? null) !== $lease)) {
                return null;
            }
            $state = $change($state);
            if (! in_array($state['phase'] ?? null, self::PHASES, true)) {
                throw new Invalid('merge.json: unknown phase '.json_encode($state['phase'] ?? null));
            }
            $this->write($state);

            return $state;
        });
    }

    /** Forgets the merge in flight (only while it is still lease $lease when one is given). */
    public function clear(?string $lease = null): void
    {
        $this->locked(function () use ($lease) {
            if ($lease === null || (Runtime::readJson($this->file())['lease'] ?? null) === $lease) {
                @unlink($this->file());
            }
        });
    }

    /**
     * A `merge` log entry with $result's fields; Invalid on a missing or unknown field.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function entry(string $result, array $fields): array
    {
        [$required, $optional] = self::RESULTS[$result] ?? throw new Invalid("unknown merge result '{$result}'");
        $missing = array_diff($required, array_keys($fields));
        $unknown = array_diff(array_keys($fields), $required, $optional);
        if ($missing !== [] || $unknown !== []) {
            throw new Invalid("merge result {$result}: ".implode(', ', [...array_map(fn ($f) => "{$f} missing", $missing), ...array_map(fn ($f) => "{$f} unknown", $unknown)]));
        }

        return ['event' => 'merge', 'result' => $result] + $fields;
    }

    /**
     * What the merger's turn of the merge $state is about: `conflicts in app.php` or ``red: `php artisan test` ``; null
     * in any other phase.
     *
     * @param  array<string, mixed>  $state
     */
    public static function turn(array $state): ?string
    {
        return match ($state['phase'] ?? null) {
            self::CONFLICT => 'conflicts in '.implode(', ', (array) ($state['conflicts'] ?? [])),
            self::RED => "red: `{$state['failure']['command']}`",
            default => null,
        };
    }

    /**
     * The log entry for a staged merger result whose merge is over.
     *
     * @return array<string, string>
     */
    public static function moot(string $hash, string $reason): array
    {
        return ['event' => 'merge_moot', 'hash' => $hash, 'reason' => $reason];
    }

    /** The lock merge.json and merge-seen.json are read and written under. */
    public function lockFile(): string
    {
        return $this->paths->ensureRuntime().'/merge.lock';
    }

    private function file(): string
    {
        return $this->paths->runtime('merge.json');
    }

    /** @param  array<string, mixed>  $state */
    private function write(array $state): void
    {
        $known = array_intersect_key(array_flip(self::FIELDS), $state);
        Runtime::writeJson($this->file(), array_replace($known, array_intersect_key($state, $known)) + $state);
    }

    private function locked(Closure $fn): mixed
    {
        $lock = Lock::exclusive($this->lockFile(), 5);
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }
}
