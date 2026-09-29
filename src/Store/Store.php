<?php

namespace PetarSpasic\Kanban\Store;

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

    /** Creates or updates a board (and its epic when missing). @param  array<string, mixed>  $data */
    public function saveBoard(BoardRef $ref, array $data, Actor $by): void;

    /** ready → doing with a claim; with sync=on the claim is pushed or the card is lost (LostClaim). */
    public function claim(string $id, Claim $claim, Actor $by): Card;

    public function sync(): SyncResult;

    /** Writes waiting in the journal (not yet committed). */
    public function pending(): int;

    public function flush(Actor $by): void;

    /** Changes whenever any board file changes (UI ETag). */
    public function fingerprint(?BoardRef $board = null): string;
}
