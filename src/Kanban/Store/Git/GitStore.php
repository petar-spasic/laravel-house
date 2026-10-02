<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use Closure;
use Illuminate\Support\Str;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Schema\CrossCardRules;
use PetarSpasic\LaravelHouse\Kanban\Schema\Validator;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Board;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Changes;
use PetarSpasic\LaravelHouse\Kanban\Store\Claim;
use PetarSpasic\LaravelHouse\Kanban\Store\Epic;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Changed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\OldBoard;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Rev;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Store\SyncResult;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Support\Sync;
use Throwable;

/**
 * Board files in the `docs/kanban` worktree. Every write: exclusive flock → flush the journal → rev check →
 * mutate → validate → atomic write → one commit (or a journal entry when git is unusable).
 */
final class GitStore implements Store
{
    /** Epic slugs the local UI already uses as its own URL prefixes. */
    private const RESERVED_EPICS = ['cards', 'assets'];

    private const WRITE_TIMEOUT = 10.0;

    private const CLAIM_TIMEOUT = 20.0;

    private const CLAIM_PUSH_TIMEOUT = 30.0;

    /** How often a background sync tries before it gives up, and the pause in seconds between tries. */
    private const SYNC_TRIES = 3;

    private const SYNC_PAUSE = 2;

    private readonly BoardRepo $repo;

    private readonly Journal $journal;

    private readonly SyncStatus $status;

    private readonly Validator $validator;

    private readonly CrossCardRules $rules;

    /** True while this instance holds the write lock (reset in finally). */
    private bool $locked = false;

    private bool $wrote = false;

    /** The name shown beside the role in log entries this instance writes; resolved on first use. */
    private ?string $person = null;

    private bool $personKnown = false;

    /** Set by the background runner while it repeats a failed try, so the status counts the run once. */
    private bool $retrying = false;

    /** Whether the project has a remote, asked once per instance. */
    private ?bool $remote = null;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config = [],
    ) {
        $this->repo = new BoardRepo($paths, $config);
        $this->journal = new Journal($paths->journalFile());
        $this->status = new SyncStatus($paths);
        $this->validator = new Validator;
        $this->rules = new CrossCardRules;
    }

    public function repo(): BoardRepo
    {
        return $this->repo;
    }

    public function snapshot(?BoardRef $board = null): Snapshot
    {
        if ($this->repo->abandoned()) {
            $this->write(fn () => null);
        }
        $snapshot = $this->read(fn () => $this->current());
        if ($board === null) {
            return $snapshot;
        }

        return new Snapshot($snapshot->kanban, $snapshot->epics, $snapshot->boards,
            array_filter($snapshot->cards, fn (Card $card) => $card->board->equals($board)), $snapshot->problems);
    }

    public function card(string $idOrPrefix): Card
    {
        return $this->snapshot()->resolve($idOrPrefix);
    }

    public function create(BoardRef $board, array $fields, Actor $by): Card
    {
        return $this->write(function () use ($board, $fields, $by) {
            $snapshot = $this->current();
            $snapshot->board($board) ?? throw new NotFound("no board {$board}");
            $remote = $this->repo->remoteIds();
            $id = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6),
                fn (string $id) => isset($snapshot->cards[$id]) || isset($remote[$id]));
            $now = Clock::now();
            $data = array_replace($this->defaults(), $this->normalizeFields($fields), [
                'id' => $id, 'created' => $now, 'updated' => $now,
            ]);
            $data['log'] = [$this->entry($by, $now) + ['event' => 'created']];
            $card = $this->cardFrom($data, $board, "{$board}/{$id}.json");
            // the id was only drawn: an error names no card
            $this->validate($snapshot, $snapshot->withCard($card), ['new card' => ['card', $card->data]]);
            $this->persist([$card->path => $card->data], [], "{$id} created [{$by->role}]", $by);

            return $card;
        });
    }

    public function update(string $id, Closure $mutate, Actor $by, ?Rev $expected = null): Card
    {
        return $this->write(function () use ($id, $mutate, $by, $expected) {
            $snapshot = $this->current();
            $card = $snapshot->resolve($id);
            if ($expected !== null && ! $expected->equals($card->rev)) {
                throw new Changed("{$card->id()} changed since it was read; reload and retry");
            }
            [$data, $summary] = $this->finalize($card->data, $mutate($card->data), $by);
            if ($summary === null) {
                return $card;
            }
            $updated = $this->cardFrom($data, $card->board, $card->path);
            $this->validate($snapshot, $snapshot->withCard($updated), [$card->path => ['card', $updated->data]]);
            $this->persist([$card->path => $updated->data], [], "{$card->id()} {$summary} [{$by->role}]", $by);

            return $updated;
        });
    }

    public function relocate(string $id, BoardRef $to, Actor $by): Card
    {
        return $this->write(function () use ($id, $to, $by) {
            $snapshot = $this->current();
            $card = $snapshot->resolve($id);
            if ($card->board->equals($to)) {
                return $card;
            }
            $snapshot->board($to) ?? throw new NotFound("no board {$to}");
            $data = $card->data;
            $data['log'][] = ['event' => 'moved', 'from' => (string) $card->board, 'to' => (string) $to];
            [$data] = $this->finalize($card->data, $data, $by);
            $moved = $this->cardFrom($data, $to, "{$to}/{$card->id()}.json");
            $this->validate($snapshot, $snapshot->withCard($moved), [$moved->path => ['card', $moved->data]]);
            $this->persist([$moved->path => $moved->data], [$card->path], "{$card->id()} moved {$card->board}→{$to} [{$by->role}]", $by);

            return $moved;
        });
    }

    public function batch(Closure $plan, Actor $by, string $message): array
    {
        return $this->write(function () use ($plan, $by, $message) {
            $snapshot = $this->current();
            $changes = $plan($snapshot);
            if (! $changes instanceof Changes) {
                throw new Invalid('a batch plan returns Changes');
            }
            $gone = array_flip($changes->removed);
            $known = array_merge(array_map(fn (Epic $e) => $e->path(), $snapshot->epics), array_map(fn (Board $b) => $b->path(), $snapshot->boards),
                array_map(fn (Card $c) => $c->path, $snapshot->cards));
            foreach ($changes->removed as $path) {
                in_array($path, $known, true) || throw new NotFound("no board file {$path}");
            }
            $cards = array_filter($snapshot->cards, fn (Card $c) => ! isset($gone[$c->path]));
            $files = [];
            $checked = [];
            $deleted = $changes->removed;
            $written = [];
            foreach ($changes->cards as $id => ['card' => $card, 'data' => $data, 'to' => $to]) {
                if (isset($gone[$card->path])) {
                    throw new Invalid("{$id} is both changed and removed");
                }
                [$data, $summary] = $this->finalize($card->data, $data, $by);
                if ($summary === null && $to->equals($card->board)) {
                    continue;
                }
                $updated = $this->cardFrom($data, $to, "{$to}/{$id}.json");
                if (! $to->equals($card->board)) {
                    $snapshot->board($to) ?? throw new NotFound("no board {$to}");
                    $deleted[] = $card->path;
                }
                $cards[$id] = $updated;
                $files[$updated->path] = $updated->data;
                $checked[$updated->path] = ['card', $updated->data];
                $written[] = $updated;
            }
            $remote = $changes->created === [] ? [] : $this->repo->remoteIds();
            foreach ($changes->created as ['board' => $board, 'fields' => $fields]) {
                $snapshot->board($board) ?? throw new NotFound("no board {$board}");
                $id = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6),
                    fn (string $id) => isset($snapshot->cards[$id]) || isset($cards[$id]) || isset($remote[$id]));
                $now = Clock::now();
                $log = array_map(fn (array $entry) => isset($entry['id']) ? $entry : $this->entry($by, $now) + $entry,
                    $fields['log'] ?? [['event' => 'created']]);
                $data = array_replace($this->defaults(), $this->normalizeFields($fields),
                    ['id' => $id, 'created' => $fields['created'] ?? $now, 'updated' => $now, 'log' => $log]);
                $created = $this->cardFrom($data, $board, "{$board}/{$id}.json");
                $cards[$id] = $created;
                $files[$created->path] = $created->data;
                $checked[$created->path] = ['card', $created->data];
                $written[] = $created;
            }
            if ($files === [] && $deleted === [] && $changes->texts === []) {
                return [];
            }
            $after = new Snapshot($snapshot->kanban,
                array_filter($snapshot->epics, fn (Epic $e) => ! isset($gone[$e->path()])),
                array_filter($snapshot->boards, fn (Board $b) => ! isset($gone[$b->path()])),
                $cards, $snapshot->problems);
            $this->validate($snapshot, $after, $checked);
            $this->persist($files, $deleted, "{$message} [{$by->role}]", $by, $changes->texts);

            return $written;
        });
    }

    public function saveBoard(BoardRef $ref, array $data, Actor $by): void
    {
        $this->write(function () use ($ref, $data, $by) {
            $snapshot = $this->current();
            $now = Clock::now();
            $files = [];
            $epic = $snapshot->epic($ref->epic);
            if ($epic === null) {
                if (in_array($ref->epic, self::RESERVED_EPICS, true)) {
                    throw new Invalid("epic '{$ref->epic}' is reserved: the local UI serves /kanban/{$ref->epic}/… itself");
                }
                $orders = array_map(fn (Epic $e) => $e->order(), $snapshot->epics);
                $epic = new Epic($ref->epic, ['title' => Str::headline($ref->epic), 'goal' => '', 'done_when' => [], 'body' => '',
                    'order' => ($orders === [] ? 0 : max($orders)) + 10, 'updated' => $now], new Rev(''));
                $files[$epic->path()] = ['epic', $epic->data];
            }
            $existing = $snapshot->board($ref);
            if ($existing === null) {
                $orders = array_map(fn (Board $b) => $b->order(), array_filter($snapshot->boards, fn (Board $b) => $b->ref->epic === $ref->epic));
                $base = ['title' => Str::headline($ref->board), 'body' => '', 'order' => ($orders === [] ? 0 : max($orders)) + 10,
                    'wip' => ['doing' => (int) $snapshot->setting('max_parallel', 6)]];
            } else {
                $base = $existing->data;
            }
            $board = new Board($ref, Json::canonical(array_replace($base, $data, ['updated' => $now]), 'board'), new Rev(''));
            $files[$board->path()] = ['board', $board->data];
            $this->validate($snapshot, $snapshot->withBoard($board, $epic), $files);
            $this->persist(array_map(fn (array $file) => $file[1], $files), [], "Board {$ref} saved [{$by->role}]", $by);
        });
    }

    /**
     * Claims a ready card for $claim. Online, the claim is won only by the push that lands: each round fetches, then
     * rebases, checks the card and $verify against what origin now holds, commits the claim and pushes it once. A rejected
     * push drops that claim commit and starts over, so a competing claim, or a card that left ready, is found on fresh data.
     */
    public function claim(string $id, Claim $claim, Actor $by, ?Closure $verify = null): Card
    {
        $online = $this->syncOn() && $this->repo->hasRemote();
        for ($round = 1; ; $round++) {
            if ($online) {
                try {
                    $this->repo->fetch();
                } catch (RemoteFailed $e) {
                    throw new RemoteFailed($e->getMessage().' (while sync is on a claim needs the remote; to work on this machine only, run the same command with KANBAN_SYNC=off in front, and only when the owner agrees)');
                }
            }
            $claimed = $this->write(fn () => $this->claimRound($id, $claim, $by, $online, $verify), self::CLAIM_TIMEOUT);
            if ($claimed !== null) {
                return $claimed;
            }
            if ($round >= 3) {
                throw new RemoteFailed("claim of {$id} dropped: push kept being rejected");
            }
            // schedulers that lost together must not retry in lockstep
            usleep(random_int(50000, 300000));
        }
    }

    /** One round of claim(), under the write lock: the claimed card, or null when the push was rejected (nothing is left behind). */
    private function claimRound(string $id, Claim $claim, Actor $by, bool $online, ?Closure $verify): ?Card
    {
        if ($online) {
            $this->repo->rebase();
        }
        $snapshot = $this->current();
        $card = $snapshot->resolve($id);
        $held = $card->claim();
        if ($held !== null && [$held['by'], $held['session']] !== [$claim->by, $claim->session]) {
            throw new LostClaim("{$card->id()} is already claimed by {$held['by']}".($held['session'] ? " (session {$held['session']})" : ''));
        }
        if ($card->stage() !== 'ready') {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}, not ready");
        }
        if ($verify !== null) {
            $verify($snapshot);
        }
        $claimed = $this->update($card->id(), function (array $data) use ($claim) {
            $data['claim'] = $claim->toArray();

            return Transitions::stage($data, 'doing', 'start');
        }, $by);
        if (! $online) {
            return $claimed;
        }
        if ($this->journal->count() > 0) {
            throw new GitFailed('the claim could not be committed, so it cannot be pushed');
        }
        try {
            // a stalled remote must not hold the board lock for the default two minutes
            $pushed = $this->repo->push(self::CLAIM_PUSH_TIMEOUT);
        } catch (Throwable $e) {
            $this->repo->resetKeep('HEAD~1');

            throw $e instanceof RemoteFailed ? new RemoteFailed("claim of {$card->id()} dropped: ".$e->getMessage()) : $e;
        }
        $this->wrote = false;
        if ($pushed === 'rejected') {
            $this->repo->resetKeep('HEAD~1');

            return null;
        }
        $this->status->recordOk();

        return $claimed;
    }

    public function sync(): SyncResult
    {
        if (! $this->paths->hasBoard()) {
            throw new NotFound('no board at '.Paths::BOARD.': run `vendor/bin/kanban attach`');
        }
        if (! $this->repo->hasRemote()) {
            return new SyncResult('no-remote');
        }
        try {
            $result = $this->syncRounds();
        } catch (LockTimeout $e) {
            // waiting behind a claim or a long rebase is not a failed sync: the previous record stays and the next try goes on
            throw $e;
        } catch (Throwable $e) {
            $this->status->recordFailure($e, $this->counted(fn () => $this->repo->ahead()), $this->counted(fn () => $this->repo->behind()), $this->retrying);

            throw $e;
        }
        $this->status->recordOk();

        return $result;
    }

    /** @param  Closure(): int  $count */
    private function counted(Closure $count): int
    {
        try {
            return $count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function syncRounds(): SyncResult
    {
        $renamed = [];
        $warnings = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $exists = $this->repo->fetch();
            // in step with origin, nothing waiting in the journal, no rebase going on: there is nothing to do, so no lock and no validation.
            // Not while the last pull left the board invalid: that stays reported until a sync finds it valid again.
            if ($exists && ($this->status->read()['kind'] ?? null) !== 'invalid' && $this->repo->behind() === 0 && $this->repo->ahead() === 0 && $this->journal->count() === 0 && ! $this->repo->rebasing()) {
                return new SyncResult('up-to-date', 0, 0, $renamed, $warnings);
            }
            [$ahead, $pulled] = $this->write(function () use ($exists, &$renamed, &$warnings) {
                $pulled = $exists ? $this->pull($renamed, $warnings) : 0;
                // a board in the older format is pulled and pushed as it is: the upgrade validates it
                $snapshot = $this->load();
                $errors = $snapshot->isOld() ? [] : $this->problems($snapshot);
                if ($errors !== []) {
                    throw new Invalid('the board is invalid after the pull; fix it, then sync again', $errors);
                }

                return [$this->repo->ahead(), $pulled];
            });
            if ($ahead === 0) {
                return new SyncResult($pulled > 0 ? 'pulled' : 'up-to-date', $pulled, 0, $renamed, $warnings);
            }
            if ($this->repo->push() === 'ok') {
                return new SyncResult('pushed', $pulled, $ahead, $renamed, $warnings);
            }
            // schedulers that finish together must not burn all three rounds in lockstep
            usleep(random_int(50000, 300000));
        }
        throw new RemoteFailed('push of '.BoardRepo::BRANCH.' kept being rejected (3 attempts)');
    }

    /**
     * Under the write lock: rebases this clone's commits onto the fetched origin, with the local moves undone and the
     * edits of cards origin moved or deleted set aside first. Returns how many commits were pulled.
     *
     * @param  array<string, string>  $renamed  old id => new id of the cards re-id'd
     * @param  list<string>  $warnings
     */
    private function pull(array &$renamed, array &$warnings): int
    {
        $behind = $this->repo->behind() > 0;
        $moves = [];
        $aside = [];
        $displaced = [];
        $rebased = false;
        $original = $this->repo->headRev();
        try {
            if ($behind) {
                $this->undoLocalMoves($moves);
                $this->setAsideEditsOfMovedCards($aside);
                $this->setAsideEditsOfDeletedCards($displaced);
            }
            $renamed += $this->reIdCollisions();
            $pulled = $this->repo->rebase();
            $rebased = true;
        } finally {
            $this->afterRebase($aside, $moves, $rebased, $original, $displaced);
        }
        foreach ($displaced as $id => $file) {
            $warnings[] = "{$id} was deleted on ".$this->repo->remote().'; the edits made here are kept in '.$this->paths->relative($file);
        }

        return $pulled;
    }

    /**
     * `fold-boards`: one work board, $into, and a version 2 board (Upgrade). It commits the way claim() does: each round
     * pulls, plans on what origin now holds, commits once and pushes once; a rejected push drops the commit and starts
     * over. An empty plan writes nothing. A dry run plans on this clone's board and writes nothing.
     */
    public function upgrade(BoardRef $into, Actor $by, bool $dryRun = false): Upgrade
    {
        $online = $this->syncOn() && $this->repo->hasRemote();
        if ($dryRun) {
            $exists = $online && $this->repo->fetch();
            $plan = $this->read(fn () => Upgrade::plan($this->load(), $this->archive(), $into, Clock::now()));
            if ($exists && ($behind = $this->repo->behind()) > 0) {
                $plan->warnings[] = "planned on this clone's board; {$this->repo->remote()} has {$behind} newer commit(s), which the fold pulls first";
            }

            return $plan;
        }
        for ($round = 1; ; $round++) {
            $exists = $online && $this->repo->fetch();
            $plan = $this->write(fn () => $this->upgradeRound($into, $by, $online, $exists), self::CLAIM_TIMEOUT);
            if ($plan !== null) {
                return $plan;
            }
            if ($round >= 3) {
                throw new RemoteFailed('fold-boards dropped: push kept being rejected');
            }
            usleep(random_int(50000, 300000));
        }
    }

    /** One round of upgrade(), under the write lock: the plan, or null when the push was rejected (nothing is left behind). */
    private function upgradeRound(BoardRef $into, Actor $by, bool $online, bool $exists): ?Upgrade
    {
        if ($this->repo->git() === null) {
            throw new GitFailed('git is unusable in the board worktree; fold-boards needs it');
        }
        $renamed = [];
        $warnings = [];
        if ($exists) {
            $this->pull($renamed, $warnings);
        }
        $snapshot = $this->load();
        $plan = Upgrade::plan($snapshot, $this->archive(), $into, Clock::now());
        $plan->warnings = $warnings;
        if ($plan->isEmpty()) {
            return $plan;
        }
        if ($plan->active !== []) {
            throw new PolicyRefused('fold-boards waits until nothing is in doing or review: '.implode(', ', $plan->active).' (drain the board first)');
        }
        $after = $plan->after;
        $files = $plan->files;
        $texts = [];
        $deleted = $plan->removed;
        foreach ($plan->cards as $id => ['data' => $data, 'path' => $path, 'raw' => $raw]) {
            $card = $snapshot->cards[$id];
            if ($raw) {
                $texts[$path] = (string) file_get_contents($this->paths->board($card->path));
            } else {
                [$data] = $this->finalize($card->data, $data, $by);
                $files[$path] = $data;
                $after = $after->withCard($this->cardFrom($data, $into, $path));
            }
            if ($card->path !== $path) {
                $deleted[] = $card->path;
            }
        }
        if ($plan->archive !== null) {
            $texts[Archive::PATH] = $plan->archive;
        }
        $errors = $this->problems($after);
        if ($errors !== []) {
            throw new Invalid('fold-boards would leave the board invalid; nothing written', $errors);
        }
        if (! $this->persist($files, array_values(array_unique($deleted)), $plan->message()." [{$by->role}]", $by, $texts)) {
            throw new GitFailed('the fold could not be committed, so it cannot be pushed');
        }
        if (! $online) {
            return $plan;
        }
        try {
            $pushed = $this->repo->push(self::CLAIM_PUSH_TIMEOUT);
        } catch (Throwable $e) {
            $this->repo->resetKeep('HEAD~1');

            throw $e instanceof RemoteFailed ? new RemoteFailed('fold-boards dropped: '.$e->getMessage()) : $e;
        }
        $this->wrote = false;
        if ($pushed === 'rejected') {
            $this->repo->resetKeep('HEAD~1');

            return null;
        }
        $this->status->recordOk();

        return $plan;
    }

    /** The archive at the board root, or null when there is none. */
    public function archive(): ?string
    {
        $file = $this->paths->board(Archive::PATH);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function maybeSync(): void
    {
        try {
            $every = (int) ($this->config['pull_seconds'] ?? 30);
            if ($every <= 0 || Sync::mode($this->config['sync'] ?? 'off') === 'off') {
                return;
            }
            $this->paths->ensureRuntime();
            // one caller at a time decides; the others go on, the interval is shared by every tab, hook and command of the clone
            $gate = Lock::try($this->paths->runtime('sync.tick.lock'));
            if ($gate === null) {
                return;
            }
            try {
                $stamp = $this->paths->runtime('sync.tick');
                $age = time() - max((int) @filemtime($stamp), (int) @filemtime($this->paths->runtime('sync.requested')));
                // a stamp from the future (clocks of a container and its host) counts as due
                if ($age >= 0 && $age < max(5, $every)) {
                    return;
                }
                touch($stamp);
                if ($this->syncOn() && $this->repo->hasRemote()) {
                    $this->requestSync();
                }
            } finally {
                $gate->release();
            }
        } catch (Throwable) {
            // a poll or a session start never fails because a sync could not be asked for
        }
    }

    /**
     * Debounced sync for sync=on: one runner at a time; it repeats while writes keep requesting it, including a write
     * that lands after the last check and before the lock is let go. A run that fails is tried again after a pause, so a
     * brief outage mends itself and a lasting one is recorded as failing by sync() and reaches the notice.
     */
    public function backgroundSync(): void
    {
        $requested = $this->paths->runtime('sync.requested');
        try {
            do {
                $lock = Lock::try($this->paths->runtime('sync.lock'));
                if ($lock === null) {
                    return;
                }
                $gaveUp = false;
                try {
                    do {
                        $mark = @file_get_contents($requested);
                        usleep(300000);
                        for ($try = 1; ; $try++) {
                            $this->retrying = $try > 1;
                            try {
                                $this->sync();

                                break;
                            } catch (Throwable) {
                                if ($try >= self::SYNC_TRIES) {
                                    $gaveUp = true;

                                    break 2;
                                }
                                sleep(self::SYNC_PAUSE);
                            }
                        }
                    } while (@file_get_contents($requested) !== $mark);
                } finally {
                    $lock->release();
                }
            } while (! $gaveUp && @file_get_contents($requested) !== $mark);
        } catch (Throwable) {
            // detached: nobody reads its output
        }
    }

    public function pending(): int
    {
        return $this->journal->count();
    }

    public function flush(Actor $by): void
    {
        $this->write(fn () => null);
    }

    public function fingerprint(?BoardRef $board = null): string
    {
        $root = $this->paths->board();
        $files = array_merge([$root.'/kanban.json'], glob($root.'/*/epic.json') ?: [],
            glob($root.'/'.($board === null ? '*/*' : (string) $board).'/*.json') ?: []);
        sort($files);
        $parts = [];
        foreach ($files as $file) {
            $stat = @stat($file);
            $parts[] = $file.':'.($stat ? $stat['size'].':'.$stat['mtime'].':'.$stat['ino'] : '-');
        }

        return sha1(implode("\n", $parts));
    }

    /** Schema and cross-card errors of a snapshot ("path: message"). */
    public function problems(?Snapshot $snapshot = null): array
    {
        $snapshot ??= $this->snapshot();

        return array_values(array_unique(array_merge($this->validator->snapshot($snapshot), $this->rules->check($snapshot))));
    }

    /**
     * `validate --fix`: canonical formatting, duplicate ids re-id'd. One commit.
     *
     * @return list<string> what was fixed
     */
    public function fix(Actor $by): array
    {
        return $this->write(function () use ($by) {
            $fixed = [];
            $files = [];
            $deleted = [];
            $snapshot = $this->current();
            $all = $this->files();
            $byStem = [];
            foreach ($all as $path => $bytes) {
                if (Json::kindOf($path) === 'card') {
                    $byStem[basename($path, '.json')][] = $path;
                }
            }
            $taken = $snapshot->cards + $this->repo->remoteIds();
            foreach ($byStem as $stem => $paths) {
                if (count($paths) < 2) {
                    continue;
                }
                $decoded = [];
                foreach ($paths as $path) {
                    $decoded[$path] = $this->decodeOrNull($all[$path]);
                }
                uasort($decoded, fn ($a, $b) => [($a['created'] ?? ''), ''] <=> [($b['created'] ?? ''), '']);
                array_shift($decoded);
                foreach ($decoded as $path => $data) {
                    if (! is_array($data)) {
                        continue;
                    }
                    $new = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6), fn ($id) => isset($taken[$id]));
                    $taken[$new] = true;
                    $data['id'] = $new;
                    $data['log'][] = ['event' => 'renamed', 'from' => $stem];
                    [$data] = $this->finalize($data, $data, $by, force: true);
                    $files[dirname($path)."/{$new}.json"] = $data;
                    $deleted[] = $path;
                    $fixed[] = "renamed {$stem} → {$new} ({$path})";
                }
            }
            foreach ($all as $path => $bytes) {
                if (isset($files[$path]) || in_array($path, $deleted, true)) {
                    continue;
                }
                $data = $this->decodeOrNull($bytes);
                if (is_array($data) && Json::encode($data, Json::kindOf($path)) !== $bytes) {
                    $files[$path] = $data;
                    $fixed[] = "canonical {$path}";
                }
            }
            if ($files !== [] || $deleted !== []) {
                $this->persist($files, $deleted, 'Kanban: validate --fix ('.count($fixed).' fixes) ['.$by->role.']', $by);
            }

            return $fixed;
        });
    }

    /** Runs $fn under the exclusive board lock, after flushing the journal. Re-entrant within this instance. */
    public function write(Closure $fn, float $timeout = self::WRITE_TIMEOUT): mixed
    {
        if ($this->locked) {
            return $fn();
        }
        $lock = Lock::exclusive($this->paths->ensureRuntime().'/lock', $timeout);
        $this->locked = true;
        $this->wrote = false;
        try {
            $this->repo->recover();
            $this->flushJournal();
            $result = $fn();
        } finally {
            $this->locked = false;
            $lock->release();
        }
        if ($this->wrote && $this->syncOn()) {
            $this->requestSync();
        }

        return $result;
    }

    /** Runs $fn under the shared board lock (skipped when this instance already holds the write lock). */
    public function read(Closure $fn): mixed
    {
        if ($this->locked) {
            return $fn();
        }
        $lock = Lock::shared($this->paths->ensureRuntime().'/lock');
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /** Whether writes are pushed and pulled: `on`, or `auto` while there is a remote. The one place that decides. */
    public function syncOn(): bool
    {
        return match (Sync::mode($this->config['sync'] ?? 'off')) {
            'on' => true,
            'auto' => $this->remote ??= $this->repo->hasRemote(),
            default => false,
        };
    }

    private function requestSync(): void
    {
        // the write is already committed: a host that cannot start the runner (exec() disabled) leaves it to the next timed pull
        try {
            file_put_contents($this->paths->runtime('sync.requested'), (string) microtime(true));
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
            $bin = dirname(__DIR__, 4).'/bin/kanban';
            exec(sprintf('cd %s && %s %s sync --background > /dev/null 2>&1 &', escapeshellarg($this->paths->main), escapeshellarg($php), escapeshellarg($bin)));
        } catch (Throwable) {
        }
    }

    /**
     * Restores the merged edits and the moves; when the rebase failed a problem here must not hide why it failed.
     *
     * @param  array<string, string>  $displaced
     */
    private function afterRebase(array $aside, array $moves, bool $rebased, ?string $original, array $displaced = []): void
    {
        if (! $rebased) {
            // The rebase did not happen: back to the local history and files as they were before the sync began.
            if ($original !== null && ($aside !== [] || $moves !== [] || $displaced !== [])) {
                try {
                    $this->repo->resetKeep($original);
                    array_map('unlink', $displaced);
                } catch (Throwable) {
                }
            }

            return;
        }
        $failure = null;
        try {
            $this->restoreAside($aside);
        } catch (Throwable $e) {
            $failure = $e;
        }
        try {
            $this->reapplyMoves($moves);
        } catch (Throwable $e) {
            $failure ??= $e;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Puts cards moved to another board here (and not yet pushed) back where origin has them and squashes the local
     * commits, so the rebase merges their content with origin's edits instead of hitting a modify/delete conflict.
     * $moves is filled once the undo is committed (card id => [path on origin, path here]); a failed commit puts the
     * files back and leaves it empty.
     *
     * @param  array<string, array{0: string, 1: string}>  $moves
     */
    private function undoLocalMoves(array &$moves): void
    {
        $head = $this->repo->headIds();
        $found = [];
        foreach ($this->repo->baseIds() as $id => $from) {
            if (isset($head[$id]) && $head[$id] !== $from) {
                $found[$id] = [$from, $head[$id]];
            }
        }
        if ($found === []) {
            return;
        }
        $paths = [];
        foreach ($found as [$from, $to]) {
            @mkdir(dirname($this->paths->board($from)), 0775, true);
            rename($this->paths->board($to), $this->paths->board($from));
            array_push($paths, $from, $to);
        }
        if (! $this->repo->commit($paths, 'Undo local card moves for sync [hook]')) {
            // Nothing was committed: put the files back where the owner moved them.
            foreach ($found as [$from, $to]) {
                if (is_file($this->paths->board($from)) && ! is_file($this->paths->board($to))) {
                    rename($this->paths->board($from), $this->paths->board($to));
                }
            }
            $this->repo->git()?->attempt(['reset', '-q', '--', ...$paths]);

            throw new GitFailed('could not commit the undone card moves');
        }
        $moves = $found;
        $this->repo->squashUnpushed('Local board changes [hook]');
    }

    /**
     * Cards origin moved to another board that were edited here: git cannot pair the moved file with the edited one
     * when the content differs a lot. Merges the two versions here, puts the edited file back to what it was at the
     * fork (so the rebase applies origin's move cleanly) and returns what to write at the new path afterwards.
     *
     * $aside is filled once the set-aside is committed; a failed commit puts the local edits back and leaves it empty.
     *
     * @param  array<string, array{from: string, to: string, ours: string, merged: string, original: string}>  $aside
     */
    private function setAsideEditsOfMovedCards(array &$aside): void
    {
        $fork = $this->repo->mergeBase();
        $head = $this->repo->headIds();
        $remote = $this->repo->remoteIds();
        $found = [];
        foreach ($this->repo->baseIds() as $id => $from) {
            $to = $remote[$id] ?? null;
            if ($to === null || $to === $from || ($head[$id] ?? null) !== $from || $fork === null) {
                continue;
            }
            $ours = $this->repo->show('HEAD', $from);
            $original = $this->repo->show($fork, $from);
            $theirs = $this->repo->show($this->repo->remoteRef(), $to);
            if ($ours === null || $original === null || $theirs === null || $ours === $original) {
                continue;
            }
            $merged = (new MergeDriver)->merge(Json::decode($original), Json::decode($theirs), Json::decode($ours), 'card');
            if ($merged === null) {
                continue;
            }
            $found[$id] = ['from' => $from, 'to' => $to, 'ours' => $ours, 'merged' => Json::encode($merged, 'card'), 'original' => $original];
        }
        if ($found === []) {
            return;
        }
        foreach ($found as $entry) {
            file_put_contents($this->paths->board($entry['from']), $entry['original']);
        }
        if (! $this->repo->commit(array_column($found, 'from'), 'Set aside local edits of cards moved on origin [hook]')) {
            foreach ($found as $entry) {
                file_put_contents($this->paths->board($entry['from']), $entry['ours']);
            }
            $this->repo->git()?->attempt(['reset', '-q', '--', ...array_column($found, 'from')]);

            throw new GitFailed('could not commit the edits set aside for sync');
        }
        $aside = $found;
        $this->repo->squashUnpushed('Local board changes [hook]');
    }

    /**
     * Cards origin deleted that were edited here: the rebase would stop on a modify/delete conflict on every sync. The
     * local copy is saved to displaced/<ID>.<blob>.json for a person to read (doctor lists it), the file goes back to what
     * it was at the fork and the local commits are squashed, so the rebase applies origin's delete cleanly.
     *
     * $displaced is filled once the restore is committed (card id => saved file); a failed commit puts the local edits
     * back and leaves it empty.
     *
     * @param  array<string, string>  $displaced
     */
    private function setAsideEditsOfDeletedCards(array &$displaced): void
    {
        $fork = $this->repo->mergeBase();
        if ($fork === null) {
            return;
        }
        $head = $this->repo->headIds();
        $remote = $this->repo->remoteIds();
        $found = [];
        foreach ($this->repo->baseIds() as $id => $path) {
            if (isset($remote[$id]) || ($head[$id] ?? null) !== $path) {
                continue;
            }
            $ours = $this->repo->show('HEAD', $path);
            $original = $this->repo->show($fork, $path);
            if ($ours === null || $original === null || $ours === $original) {
                continue;
            }
            $found[$id] = ['path' => $path, 'ours' => $ours, 'original' => $original,
                'file' => $this->paths->displaced($id.'.'.substr((string) $this->repo->blob('HEAD', $path), 0, 7).'.json')];
        }
        if ($found === []) {
            return;
        }
        @mkdir($this->paths->displaced(), 0775, true);
        foreach ($found as $entry) {
            file_put_contents($entry['file'], $entry['ours']);
            file_put_contents($this->paths->board($entry['path']), $entry['original']);
        }
        if (! $this->repo->commit(array_column($found, 'path'), 'Set aside local edits of cards deleted on origin [hook]')) {
            foreach ($found as $entry) {
                file_put_contents($this->paths->board($entry['path']), $entry['ours']);
                @unlink($entry['file']);
            }
            $this->repo->git()?->attempt(['reset', '-q', '--', ...array_column($found, 'path')]);

            throw new GitFailed('could not commit the edits set aside for sync');
        }
        $displaced = array_map(fn (array $entry) => $entry['file'], $found);
        $this->repo->squashUnpushed('Local board changes [hook]');
    }

    /**
     * After a successful rebase the merged card goes to origin's new path.
     *
     * @param  array<string, array{from: string, to: string, ours: string, merged: string}>  $aside
     */
    private function restoreAside(array $aside): void
    {
        if ($aside === []) {
            return;
        }
        $paths = [];
        foreach ($aside as $entry) {
            @mkdir(dirname($this->paths->board($entry['to'])), 0775, true);
            file_put_contents($this->paths->board($entry['to']), $entry['merged']);
            $paths[] = $entry['to'];
        }
        if (! $this->repo->commit($paths, 'Local edits of cards moved on origin, merged [hook]')) {
            throw new GitFailed('could not commit the merged card edits');
        }
    }

    /**
     * Moves the cards back to the boards they were moved to, unless origin moved them meanwhile (origin wins).
     *
     * @param  array<string, array{0: string, 1: string}>  $moves
     */
    private function reapplyMoves(array $moves): void
    {
        if ($moves === []) {
            return;
        }
        $snapshot = $this->load();
        $paths = [];
        foreach ($moves as $id => [$from, $to]) {
            if ($snapshot->card($id)?->path !== $from || ! is_dir(dirname($this->paths->board($to)))) {
                continue;
            }
            rename($this->paths->board($from), $this->paths->board($to));
            array_push($paths, $from, $to);
        }
        if ($paths !== [] && ! $this->repo->commit($paths, 'Card moves reapplied after sync [hook]')) {
            throw new GitFailed('could not commit the reapplied card moves');
        }
    }

    /**
     * Cards added here whose id also exists on origin get a fresh id before the rebase.
     *
     * @return array<string, string> old => new
     */
    private function reIdCollisions(): array
    {
        $remote = $this->repo->remoteIds();
        $base = $this->repo->baseIds();
        $snapshot = $this->load();
        $renamed = [];
        foreach ($this->repo->addedLocally() as $path) {
            $old = basename($path, '.json');
            $card = $snapshot->card($old);
            if (isset($base[$old]) || ! isset($remote[$old]) || $card === null || $card->path !== $path
                || $this->repo->blob('HEAD', $path) === $this->repo->blob($this->repo->remoteRef(), $remote[$old])) {
                continue;
            }
            $new = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6),
                fn (string $id) => isset($snapshot->cards[$id]) || isset($remote[$id]) || in_array($id, $renamed, true));
            $renamed[$old] = $new;
        }
        if ($renamed === []) {
            return [];
        }
        $by = new Actor('hook');
        $files = [];
        $deleted = [];
        foreach ($snapshot->cards as $card) {
            $data = $card->data;
            if (isset($renamed[$card->id()])) {
                $data['id'] = $renamed[$card->id()];
                $data['log'][] = ['event' => 'renamed', 'from' => $card->id(), 'reason' => 'id taken on '.$this->repo->remote()];
                $deleted[] = $card->path;
            }
            if (isset($data['depends_on'])) {
                $data['depends_on'] = array_map(fn (string $id) => $renamed[$id] ?? $id, $data['depends_on']);
            }
            if ($data !== $card->data) {
                [$data] = $this->finalize($card->data, $data, $by, force: true);
                $files[$card->board.'/'.$data['id'].'.json'] = $data;
            }
        }
        $message = implode(', ', array_map(fn ($old, $new) => "{$old}→{$new}", array_keys($renamed), $renamed));
        if (! $this->persist($files, $deleted, "Renamed {$message} (id taken on ".$this->repo->remote().') [hook]', $by)) {
            throw new GitFailed('could not commit the renamed cards');
        }
        $this->repo->squashUnpushed("Local board changes; renamed {$message} (id taken on ".$this->repo->remote().') [hook]');

        return $renamed;
    }

    /**
     * Completes a mutation: log entries get id/at/by, a stage change is checked against the transition table,
     * `updated` moves. Returns [data, commit summary], summary null when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function finalize(array $before, array $after, Actor $by, bool $force = false): array
    {
        if (! $force && ($after['id'] ?? null) !== ($before['id'] ?? null)) {
            throw new Invalid('the id of a card cannot change');
        }
        $now = Clock::now();
        $log = array_values($after['log'] ?? []);
        $new = [];
        foreach ($log as $i => $entry) {
            if (! isset($entry['id'])) {
                $log[$i] = $this->entry($by, $now) + $entry;
                $new[] = $log[$i];
            }
        }
        $from = $before['stage'] ?? null;
        $to = $after['stage'] ?? null;
        if ($from !== $to) {
            $entry = array_values(array_filter($new, fn (array $e) => $e['event'] === 'stage' && ($e['to'] ?? null) === $to))[0] ?? null;
            if ($entry === null) {
                $entry = $this->entry($by, $now) + ['event' => 'stage', 'from' => $from, 'to' => $to];
                $log[] = $entry;
                $new[] = $entry;
            }
            Transitions::check((string) $from, (string) $to, $entry['via'] ?? 'move', $by, (bool) ($entry['forced'] ?? false));
        }
        $strip = fn (array $d) => array_diff_key($d, ['updated' => 1, 'log' => 1]);
        $changed = array_keys(array_filter($strip($after), fn ($v, $k) => ! array_key_exists($k, $before) || $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $changed = array_merge($changed, array_keys(array_diff_key($strip($before), $after)));
        if ($new === [] && $changed === []) {
            return [$before, null];
        }
        $explained = array_filter($new, fn (array $e) => in_array($e['event'], ['set', 'stage', 'moved', 'renamed'], true)) !== [];
        if (! $explained && $changed !== []) {
            sort($changed);
            $entry = $this->entry($by, $now) + ['event' => 'set', 'fields' => $changed];
            $log[] = $entry;
            $new[] = $entry;
        }
        $after['log'] = $log;
        $after['updated'] = $now;

        return [Json::canonical($after, 'card'), $this->summary($new)];
    }

    /**
     * The start of a new log entry: id, time, the role, and the person when one is known.
     *
     * @return array<string, string>
     */
    private function entry(Actor $by, string $now): array
    {
        $entry = ['id' => Ids::log(), 'at' => $now, 'by' => $by->role];
        if (($who = $this->person()) !== null) {
            $entry['who'] = $who;
        }

        return $entry;
    }

    /**
     * Who is at this keyboard: KANBAN_USER, else git's user.name, else the name of an author set on purpose
     * (KANBAN_GIT_AUTHOR), else nobody. Never a guess such as the login or the host, and never the UI's built-in author:
     * a wrong name shown as a fact is worse than none.
     */
    private function person(): ?string
    {
        if ($this->personKnown) {
            return $this->person;
        }
        $this->personKnown = true;
        $author = (string) ($this->config['ui']['git_author'] ?? '');
        $name = (string) ($this->config['user'] ?? '') ?: (string) $this->repo->userName()
            ?: ($author !== '' && $author !== BoardRepo::DEFAULT_AUTHOR && preg_match('/^(.*?)\s*<[^>]*>$/', $author, $m) === 1 ? $m[1] : '');
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $name)), 0, 80);

        return $this->person = $name === '' ? null : $name;
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function summary(array $entries): string
    {
        foreach ($entries as $entry) {
            if ($entry['event'] === 'stage') {
                return "stage {$entry['from']}→{$entry['to']}";
            }
        }
        $parts = array_map(fn (array $e) => $e['event'] === 'set' ? 'set '.implode(',', $e['fields']) : $e['event'], $entries);

        return implode('; ', array_unique($parts));
    }

    /**
     * @param  Snapshot  $before  the board as it was
     * @param  Snapshot  $after  the board with the change applied
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $changed  path => [kind, data]
     */
    private function validate(Snapshot $before, Snapshot $after, array $changed): void
    {
        $errors = [];
        foreach ($changed as $path => [$kind, $data]) {
            foreach ($this->validator->validate($kind, $data) as $error) {
                $errors[] = "{$path}: {$error}";
            }
        }
        $errors = array_merge($errors, array_values(array_diff($this->rules->check($after), $this->rules->check($before))));
        if ($errors !== []) {
            throw new Invalid('invalid: '.$errors[0], $errors);
        }
    }

    /**
     * Writes files atomically and commits them; journals the write when git is unusable.
     *
     * @param  array<string, array<string, mixed>>  $files  relative path => data
     * @param  list<string>  $deleted  relative paths
     * @param  array<string, string>  $texts  relative path => bytes, written as they are
     */
    private function persist(array $files, array $deleted, string $message, Actor $by, array $texts = []): bool
    {
        foreach ($files as $path => $data) {
            Json::write($this->paths->board($path), Json::encode($data, Json::kindOf($path)));
        }
        foreach ($texts as $path => $bytes) {
            Json::write($this->paths->board($path), $bytes);
        }
        foreach ($deleted as $path) {
            @unlink($this->paths->board($path));
        }
        $paths = array_values(array_unique(array_merge(array_keys($files), array_keys($texts), $deleted)));
        $this->wrote = true;
        if ($this->repo->commit($paths, $message)) {
            return true;
        }
        $this->journal->append(['at' => Clock::now(), 'by' => $by->role, 'message' => $message, 'paths' => $paths]);

        return false;
    }

    private function flushJournal(): void
    {
        $entries = $this->journal->entries();
        if ($entries === []) {
            return;
        }
        $paths = array_values(array_unique(array_merge(...array_map(fn (array $e) => $e['paths'] ?? [], $entries))));
        $messages = array_map(fn (array $e) => $e['message'] ?? '', $entries);
        $subject = count($messages) === 1 ? 'Owner via UI: '.$messages[0] : 'Owner via UI: '.count($messages).' changes';
        if ($this->repo->commit($paths, $subject."\n\n".implode("\n", $messages))) {
            $this->journal->clear();
        }
    }

    /** @return array<string, string> relative path => bytes of every board JSON file */
    private function files(): array
    {
        $root = $this->paths->board();
        $files = [];
        foreach (array_merge([$root.'/kanban.json'], glob($root.'/*/epic.json') ?: [], glob($root.'/*/*/*.json') ?: []) as $file) {
            if (is_file($file)) {
                $files[substr($file, strlen($root) + 1)] = (string) file_get_contents($file);
            }
        }

        return $files;
    }

    private function load(): Snapshot
    {
        if (! $this->paths->hasBoard()) {
            throw new NotFound('no board at '.Paths::BOARD.': run `php artisan kanban:install`, or `vendor/bin/kanban attach` on a clone');
        }
        $problems = [];
        $kanban = [];
        $epics = [];
        $boards = [];
        $cards = [];
        foreach ($this->files() as $path => $bytes) {
            try {
                $data = Json::decode($bytes);
            } catch (Invalid $e) {
                $problems[$path][] = $e->getMessage();

                continue;
            }
            $rev = Rev::of($bytes);
            $segments = explode('/', $path);
            try {
                match (Json::kindOf($path)) {
                    'kanban' => $kanban = $data,
                    'epic' => $epics[$segments[0]] = new Epic($segments[0], $data, $rev),
                    'board' => $boards[$segments[0].'/'.$segments[1]] = new Board(new BoardRef($segments[0], $segments[1]), $data, $rev),
                    'card' => $this->loadCard($data, $path, $rev, $cards, $problems),
                };
            } catch (Invalid $e) {
                $problems[$path][] = $e->getMessage();
            }
        }
        foreach ($boards as $board) {
            if (! isset($epics[$board->ref->epic])) {
                $problems[$board->ref->epic.'/epic.json'][] = 'missing (a board needs its epic)';
            }
        }

        return new Snapshot($kanban, $epics, $boards, $cards, $problems);
    }

    /**
     * The board for a command, the UI or a hook: an older format is refused with one line. Sync and the upgrade read it
     * through load().
     */
    private function current(): Snapshot
    {
        $snapshot = $this->load();
        if ($snapshot->isOld()) {
            throw new OldBoard;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Card>  $cards
     * @param  array<string, list<string>>  $problems
     */
    private function loadCard(array $data, string $path, Rev $rev, array &$cards, array &$problems): void
    {
        [$epic, $board, $file] = explode('/', $path);
        $id = basename($file, '.json');
        if (($data['id'] ?? null) !== $id) {
            $problems[$path][] = 'id must equal the file name';

            return;
        }
        if (isset($cards[$id])) {
            $problems[$path][] = "duplicate id {$id} (also {$cards[$id]->path}); run `kanban validate --fix`";

            return;
        }
        $cards[$id] = new Card($data, new BoardRef($epic, $board), $path, $rev);
    }

    /** @param  array<string, mixed>  $data */
    private function cardFrom(array $data, BoardRef $board, string $path): Card
    {
        $data = Json::canonical($data, 'card');

        return new Card($data, $board, $path, Rev::of(Json::encode($data, 'card')));
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['id' => null, 'type' => 'feature', 'title' => '', 'stage' => 'backlog', 'priority' => 'normal', 'labels' => [], 'body' => '',
            'acceptance' => [], 'depends_on' => [], 'blocked' => null, 'claim' => null, 'work' => null, 'created' => null, 'updated' => null, 'log' => []];
    }

    /**
     * Acceptance given as strings becomes numbered criteria.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalizeFields(array $fields): array
    {
        if (isset($fields['acceptance'])) {
            $fields['acceptance'] = array_values(array_map(fn ($item, int $i) => is_string($item)
                ? ['id' => $i + 1, 'text' => $item, 'done' => false] : $item, $fields['acceptance'], array_keys(array_values($fields['acceptance']))));
        }
        unset($fields['id'], $fields['created'], $fields['updated'], $fields['log']);

        return $fields;
    }

    /** @return array<mixed>|null */
    private function decodeOrNull(string $bytes): ?array
    {
        try {
            return Json::decode($bytes);
        } catch (Invalid) {
            return null;
        }
    }
}
