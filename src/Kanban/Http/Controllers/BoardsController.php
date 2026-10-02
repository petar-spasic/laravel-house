<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http\Controllers;

use Illuminate\Http\Request;
use PetarSpasic\LaravelHouse\Kanban\Http\Api;
use PetarSpasic\LaravelHouse\Kanban\Http\Presenter;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\SyncStatus;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\AgentStates;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\HttpFoundation\Response;

class BoardsController
{
    public function __construct(private readonly Store $store, private readonly Paths $paths) {}

    public function index(Request $request): Response
    {
        return Api::run(fn () => $this->boards($request));
    }

    public function show(Request $request, string $epic, string $board): Response
    {
        return Api::run(fn () => $this->board($request, $epic, $board));
    }

    private function boards(Request $request): Response
    {
        if (! $this->paths->hasBoard()) {
            return Api::json(['key' => null, 'epics' => [], 'notices' => [
                'No board at docs/kanban on this machine: run `vendor/bin/kanban attach` (or `php artisan kanban:install` in a new project).',
            ]]);
        }

        return $this->cached($request, 'boards', function () {
            $snapshot = $this->store->snapshot();

            $presenter = new Presenter($snapshot, $this->paths);

            return ['key' => $snapshot->key(), 'layout' => $presenter->layout(), 'notices' => $this->notices($snapshot), 'epics' => $presenter->epics()];
        });
    }

    private function board(Request $request, string $epic, string $board): Response
    {
        $ref = new BoardRef($epic, $board);
        if (! $this->paths->hasBoard()) {
            throw new NotFound("no board {$ref}");
        }
        $all = $request->boolean('all');

        return $this->cached($request, "{$ref}|{$all}", function () use ($ref, $all) {
            $snapshot = $this->store->snapshot();
            $presenter = new Presenter($snapshot, $this->paths);
            $data = $presenter->board($ref, $all) ?? abort(404, "no board {$ref}");

            return $data + ['layout' => $presenter->layout(), 'notices' => $this->notices($snapshot)];
        });
    }

    /** @return list<string> */
    private function notices(Snapshot $snapshot): array
    {
        $notices = [];
        if (($pending = $this->store->pending()) > 0) {
            $notices[] = "{$pending} UI ".($pending === 1 ? 'write is' : 'writes are').' saved but not committed yet: the next CLI write or `kanban sync` commits them.';
        }
        if ($snapshot->problems !== []) {
            $notices[] = count($snapshot->problems).' board files have problems: run `vendor/bin/kanban validate`.';
        }
        if (($sync = (new SyncStatus($this->paths))->notice()) !== null) {
            $notices[] = $sync;
        }

        return $notices;
    }

    /**
     * Weak ETag of the board files, the writes waiting in the journal, what the last sync said and the agents' state: a
     * 304 reads no board file and takes no lock.
     *
     * @param  callable(): array<string, mixed>  $build
     */
    private function cached(Request $request, string $what, callable $build): Response
    {
        // the one side effect of a read: at most once per pull_seconds it asks for a sync, so what others pushed reaches an open board
        $this->store->maybeSync();
        $etag = 'W/"'.sha1(implode('|', [
            $what, $this->store->fingerprint(), $this->store->pending(), (new SyncStatus($this->paths))->digest(),
            AgentStates::signature($this->paths, Presenter::staleMinutes($this->paths)),
        ])).'"';
        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, ['ETag' => $etag]);
        }

        return Api::json($build(), 200, ['ETag' => $etag]);
    }
}
