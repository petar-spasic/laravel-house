<?php

namespace PetarSpasic\Kanban\Store\Git;

use Closure;
use Illuminate\Support\Str;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Schema\CrossCardRules;
use PetarSpasic\Kanban\Schema\Validator;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Board;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\CardType;
use PetarSpasic\Kanban\Store\Claim;
use PetarSpasic\Kanban\Store\Epic;
use PetarSpasic\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\Kanban\Store\Rev;
use PetarSpasic\Kanban\Store\Snapshot;
use PetarSpasic\Kanban\Store\Stage;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Store\SyncResult;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Ids;
use PetarSpasic\Kanban\Support\Json;
use PetarSpasic\Kanban\Support\Lock;
use PetarSpasic\Kanban\Support\Paths;

/**
 * Board files in the `docs/kanban` worktree. Every write: exclusive flock → flush the journal → rev check →
 * mutate → validate → atomic write → one commit (or a journal entry when git is unusable).
 */
final class GitStore implements Store
{
    private const WRITE_TIMEOUT = 10.0;

    private const CLAIM_TIMEOUT = 20.0;

    private readonly BoardRepo $repo;

    private readonly Journal $journal;

    private readonly Validator $validator;

    private readonly CrossCardRules $rules;

    /** True while this instance holds the write lock (reset in finally). */
    private bool $locked = false;

    private bool $wrote = false;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config = [],
    ) {
        $this->repo = new BoardRepo($paths, $config);
        $this->journal = new Journal($paths->journalFile());
        $this->validator = new Validator;
        $this->rules = new CrossCardRules;
    }

    public function repo(): BoardRepo
    {
        return $this->repo;
    }

    public function snapshot(?BoardRef $board = null): Snapshot
    {
        $snapshot = $this->read(fn () => $this->load());
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
            $snapshot = $this->load();
            $target = $snapshot->board($board) ?? throw new NotFound("no board {$board}");
            $remote = $this->repo->remoteIds();
            $id = Ids::card($snapshot->key(), (int) $snapshot->setting('id_length', 6),
                fn (string $id) => isset($snapshot->cards[$id]) || isset($remote[$id]));
            $now = Clock::now();
            $data = array_replace($this->defaults($target->kind()), $this->normalizeFields($fields), [
                'id' => $id, 'created' => $now, 'updated' => $now,
            ]);
            $data['log'] = [['id' => Ids::log(), 'at' => $now, 'by' => $by->role, 'event' => 'created']];
            $card = $this->cardFrom($data, $board, "{$board}/{$id}.json");
            $this->validate($snapshot, $snapshot->withCard($card), [$card->path => ['card', $card->data]]);
            $this->persist([$card->path => $card->data], [], "{$id} created [{$by->role}]", $by);

            return $card;
        });
    }

    public function update(string $id, Closure $mutate, Actor $by, ?Rev $expected = null): Card
    {
        return $this->write(function () use ($id, $mutate, $by, $expected) {
            $snapshot = $this->load();
            $card = $snapshot->resolve($id);
            if ($expected !== null && ! $expected->equals($card->rev)) {
                throw new Conflict("{$card->id()} changed since it was read; reload and retry");
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
            $snapshot = $this->load();
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

    public function saveBoard(BoardRef $ref, array $data, Actor $by): void
    {
        $this->write(function () use ($ref, $data, $by) {
            $snapshot = $this->load();
            $now = Clock::now();
            $files = [];
            $epic = $snapshot->epic($ref->epic);
            if ($epic === null) {
                $orders = array_map(fn (Epic $e) => $e->order(), $snapshot->epics);
                $epic = new Epic($ref->epic, ['title' => Str::headline($ref->epic), 'goal' => '', 'done_when' => [], 'body' => '',
                    'order' => ($orders === [] ? 0 : max($orders)) + 10, 'updated' => $now], new Rev(''));
                $files[$epic->path()] = ['epic', $epic->data];
            }
            $existing = $snapshot->board($ref);
            if ($existing === null) {
                $orders = array_map(fn (Board $b) => $b->order(), array_filter($snapshot->boards, fn (Board $b) => $b->ref->epic === $ref->epic));
                $kind = $data['kind'] ?? 'work';
                $base = ['title' => Str::headline($ref->board), 'kind' => $kind, 'body' => '', 'order' => ($orders === [] ? 0 : max($orders)) + 10,
                    'wip' => $kind === 'work' ? ['doing' => (int) $snapshot->setting('max_parallel', 6)] : []];
            } else {
                $base = $existing->data;
            }
            $board = new Board($ref, Json::canonical(array_replace($base, $data, ['updated' => $now]), 'board'), new Rev(''));
            $files[$board->path()] = ['board', $board->data];
            $this->validate($snapshot, $snapshot->withBoard($board, $epic), $files);
            $this->persist(array_map(fn (array $file) => $file[1], $files), [], "Board {$ref} saved [{$by->role}]", $by);
        });
    }

    public function claim(string $id, Claim $claim, Actor $by): Card
    {
        $online = $this->syncOn() && $this->repo->hasRemote();
        if ($online) {
            $this->repo->fetch();
        }

        return $this->write(function () use ($id, $claim, $by, $online) {
            if ($online) {
                $this->repo->rebase();
            }
            $card = $this->load()->resolve($id);
            $held = $card->claim();
            if ($held !== null && [$held['by'], $held['session']] !== [$claim->by, $claim->session]) {
                throw new LostClaim("{$card->id()} is already claimed by {$held['by']}".($held['session'] ? " (session {$held['session']})" : ''));
            }
            if ($card->stage() !== 'ready') {
                throw new PolicyRefused("{$card->id()} is {$card->stage()}, not ready");
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
            $this->pushClaim($claimed);
            $this->wrote = false;

            return $claimed;
        }, self::CLAIM_TIMEOUT);
    }

    public function sync(): SyncResult
    {
        if (! $this->paths->hasBoard()) {
            throw new NotFound('no board at '.Paths::BOARD.': run `vendor/bin/kanban attach`');
        }
        if (! $this->repo->hasRemote()) {
            return new SyncResult('no-remote');
        }
        $renamed = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $exists = $this->repo->fetch();
            [$ahead, $pulled] = $this->write(function () use ($exists, &$renamed) {
                $pulled = 0;
                if ($exists) {
                    $renamed += $this->reIdCollisions();
                    $pulled = $this->repo->rebase();
                }
                $errors = $this->problems($this->load());
                if ($errors !== []) {
                    throw new Invalid('the board is invalid after the pull; fix it, then sync again', $errors);
                }

                return [$this->repo->ahead(), $pulled];
            });
            if ($ahead === 0) {
                return new SyncResult($pulled > 0 ? 'pulled' : 'up-to-date', $pulled, 0, $renamed);
            }
            if ($this->repo->push() === 'ok') {
                return new SyncResult('pushed', $pulled, $ahead, $renamed);
            }
        }
        throw new RemoteFailed('push of '.BoardRepo::BRANCH.' kept being rejected (3 attempts)');
    }

    /** Debounced sync for sync=on: one runner at a time; it repeats while writes keep requesting it. */
    public function backgroundSync(): void
    {
        $lock = Lock::try($this->paths->runtime('sync.lock'));
        if ($lock === null) {
            return;
        }
        $requested = $this->paths->runtime('sync.requested');
        try {
            do {
                $mark = @file_get_contents($requested);
                usleep(300000);
                try {
                    $this->sync();
                } catch (KanbanException) {
                    return;
                }
            } while (@file_get_contents($requested) !== $mark);
        } finally {
            $lock->release();
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
     * `validate --fix`: canonical formatting, duplicate ids re-id'd, superseded_by made consistent. One commit.
     *
     * @return list<string> what was fixed
     */
    public function fix(Actor $by): array
    {
        return $this->write(function () use ($by) {
            $fixed = [];
            $files = [];
            $deleted = [];
            $snapshot = $this->load();
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
            foreach ($snapshot->cards as $card) {
                if ($card->stage() !== 'decided') {
                    continue;
                }
                foreach ($card->data['supersedes'] ?? [] as $old) {
                    $target = $snapshot->card($old);
                    if ($target === null || $target->stage() !== 'decided') {
                        continue;
                    }
                    $data = $target->data;
                    $data['superseded_by'] = $card->id();
                    [$data] = $this->finalize($target->data, Transitions::stage($data, 'superseded', 'auto'), $by);
                    $files[$target->path] = $data;
                    $fixed[] = "{$old} superseded by {$card->id()}";
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

    private function syncOn(): bool
    {
        return ($this->config['sync'] ?? 'off') === 'on';
    }

    private function requestSync(): void
    {
        file_put_contents($this->paths->runtime('sync.requested'), (string) microtime(true));
        $php = PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
        $bin = dirname(__DIR__, 3).'/bin/kanban';
        exec(sprintf('cd %s && %s %s sync --background > /dev/null 2>&1 &', escapeshellarg($this->paths->main), escapeshellarg($php), escapeshellarg($bin)));
    }

    /** Pushes the claim commit at HEAD; a rejected push whose card blob changed on origin loses the claim. */
    private function pushClaim(Card $card): void
    {
        $base = $this->repo->blob('HEAD~1', $card->path);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $pushed = $this->repo->push();
            } catch (RemoteFailed $e) {
                $this->repo->resetKeep('HEAD~1');
                throw new RemoteFailed("claim of {$card->id()} dropped: ".$e->getMessage());
            }
            if ($pushed === 'ok') {
                return;
            }
            $this->repo->fetch();
            if ($this->repo->blob($this->repo->remoteRef(), $card->path) !== $base) {
                $this->repo->resetKeep('HEAD~1');
                $this->repo->rebase();
                throw new LostClaim("{$card->id()} changed on ".$this->repo->remote().' first; the claim is lost, pick another card');
            }
            $this->repo->rebase();
        }
        $this->repo->resetKeep('HEAD~1');
        throw new RemoteFailed("claim of {$card->id()} dropped: push kept being rejected");
    }

    /**
     * Cards added here whose id also exists on origin get a fresh id before the rebase.
     *
     * @return array<string, string> old => new
     */
    private function reIdCollisions(): array
    {
        $remote = $this->repo->remoteIds();
        $snapshot = $this->load();
        $renamed = [];
        foreach ($this->repo->addedLocally() as $path) {
            $old = basename($path, '.json');
            $card = $snapshot->card($old);
            if (! isset($remote[$old]) || $card === null || $card->path !== $path
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
            foreach (['depends_on', 'supersedes'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = array_map(fn (string $id) => $renamed[$id] ?? $id, $data[$key]);
                }
            }
            if (isset($data['superseded_by'])) {
                $data['superseded_by'] = $renamed[$data['superseded_by']] ?? $data['superseded_by'];
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
                $log[$i] = ['id' => Ids::log(), 'at' => $now, 'by' => $by->role] + $entry;
                $new[] = $log[$i];
            }
        }
        $from = $before['stage'] ?? null;
        $to = $after['stage'] ?? null;
        if ($from !== $to) {
            $entry = array_values(array_filter($new, fn (array $e) => $e['event'] === 'stage' && ($e['to'] ?? null) === $to))[0] ?? null;
            if ($entry === null) {
                $entry = ['id' => Ids::log(), 'at' => $now, 'by' => $by->role, 'event' => 'stage', 'from' => $from, 'to' => $to];
                $log[] = $entry;
                $new[] = $entry;
            }
            Transitions::check(CardType::kindOf((string) ($after['type'] ?? '')), (string) $from, (string) $to, $entry['via'] ?? 'move', $by, (bool) ($entry['forced'] ?? false));
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
            $entry = ['id' => Ids::log(), 'at' => $now, 'by' => $by->role, 'event' => 'set', 'fields' => $changed];
            $log[] = $entry;
            $new[] = $entry;
        }
        $after['log'] = $log;
        $after['updated'] = $now;

        return [Json::canonical($after, 'card'), $this->summary($new)];
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
     */
    private function persist(array $files, array $deleted, string $message, Actor $by): bool
    {
        foreach ($files as $path => $data) {
            Json::write($this->paths->board($path), Json::encode($data, Json::kindOf($path)));
        }
        foreach ($deleted as $path) {
            @unlink($this->paths->board($path));
        }
        $paths = array_values(array_unique(array_merge(array_keys($files), $deleted)));
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
    private function defaults(string $kind): array
    {
        $common = ['id' => null, 'type' => $kind === 'decisions' ? 'decision' : 'feature', 'title' => '', 'stage' => Stage::initial($kind),
            'priority' => 'normal', 'labels' => [], 'body' => ''];

        return $kind === 'decisions'
            ? $common + ['why' => '', 'decided_on' => null, 'supersedes' => [], 'superseded_by' => null, 'resolution' => null, 'source' => null,
                'created' => null, 'updated' => null, 'log' => []]
            : $common + ['acceptance' => [], 'depends_on' => [], 'blocked' => null, 'claim' => null, 'work' => null,
                'created' => null, 'updated' => null, 'log' => []];
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
