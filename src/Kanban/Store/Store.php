<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

use Closure;

interface Store
{
    /** Everything on the board (or one board), read under a shared lock. */
    public function snapshot(?BoardRef $board = null): Snapshot;

    /** A card by full id or unique prefix (≥ 3 characters after the key); NotFound on none or ambiguity. */
    public function card(string $idOrPrefix): Card;

    /** @param  array<string, mixed>  $fields */
    public function create(BoardRef $board, array $fields, Actor $by): Card;

    /**
     * $mutate receives the card data and returns it changed. Log entries it appends without an id are
     * completed (id, at, by); a stage change needs a `stage` entry carrying `via` (Transitions::stage()).
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mutate
     */
    public function update(string $id, Closure $mutate, Actor $by, ?Rev $expected = null): Card;

    public function relocate(string $id, BoardRef $to, Actor $by): Card;

    /**
     * New and changed cards, board files and one root Markdown file in one commit: $plan gets the snapshot under the write lock and
     * says what changes; the whole board after it is validated before anything is written.
     *
     * @param  Closure(Snapshot): Changes  $plan
     * @return list<Card> the cards written
     */
    public function batch(Closure $plan, Actor $by, string $message): array;

    /** Creates or updates a board. @param  array<string, mixed>  $data */
    public function saveBoard(BoardRef $ref, array $data, Actor $by): void;

    /** Creates or updates an epic (`_epics/<slug>.json`). @param  array<string, mixed>  $data */
    public function saveEpic(string $slug, array $data, Actor $by): Epic;

    /**
     * A claim on the card; with sync=on the claim is pushed or the card is lost (LostClaim). $verify gets the snapshot the
     * claim is made against (origin's, after the pull, when sync=on) and throws to refuse. $work is merged into the card in
     * the claim's own commit, so a claim that lands always says where its work goes. $mutate makes the rest of the change:
     * by default ready → doing (the card must be ready); a planner's claim keeps it in planning.
     *
     * @param  (Closure(Snapshot): void)|null  $verify
     * @param  array<string, mixed>|null  $work
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $mutate
     */
    public function claim(string $id, Claim $claim, Actor $by, ?Closure $verify = null, ?array $work = null, ?Closure $mutate = null): Card;

    /**
     * Compare-and-set of the merge lease (`merge` in kanban.json), won like a claim: with sync on, by the push that lands,
     * fetched, rebased and decided again each round (three, then RemoteFailed). $decide gets the lease on the board and the
     * snapshot (origin's, after the pull, when sync is on) under the write lock, and returns the new lease (null frees it),
     * the cards to change in the same commit (id => mutation) and the commit message; it throws to refuse (Waiting,
     * LostClaim). Returning the lease it was given and no card writes nothing. A new lease without `who` gets the writer's,
     * as a log entry does; kanban.json's `updated` is left alone.
     * Returns the lease after the write, or null: freed, or the board lock was busy and $try (a beat) skipped the write.
     *
     * @param  Closure(array<string, mixed>|null, Snapshot): array{0: array<string, mixed>|null, 1: array<string, Closure(array<string, mixed>): array<string, mixed>>, 2: string}  $decide
     * @return array<string, mixed>|null
     */
    public function lease(Closure $decide, Actor $by, bool $try = false, float $pushTimeout = 30): ?array;

    public function sync(): SyncResult;

    /**
     * With sync on: starts a background sync unless one was asked for within pull_seconds. Cheap, never waits for git,
     * never throws. $wait syncs here instead, for a caller that acts on the pulled board next (`kanban run`).
     */
    public function maybeSync(bool $wait = false): void;

    /** Writes waiting in the journal (not yet committed). */
    public function pending(): int;

    public function flush(Actor $by): void;

    /** Changes whenever any board file changes (UI ETag). */
    public function fingerprint(?BoardRef $board = null): string;
}
