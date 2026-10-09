<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Policy\MergeQueue;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Brief;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\SyncStatus;
use PetarSpasic\LaravelHouse\Kanban\Support\Sync;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:status')]
class StatusCommand extends Command
{
    protected $signature = 'kanban:status {--json : JSON output}';

    protected $description = 'Board summary: WIP, cards in flight, blocked, next, checks';

    protected function perform(): int
    {
        $store = $this->store();
        $this->gitStore()?->maybeSync();
        if (! $this->option('json')) {
            foreach ((new Brief($store, $this->paths(), $this->config()))->lines($this->actor()->session) as $line) {
                $this->say($line);
            }

            return self::SUCCESS;
        }
        $snapshot = $store->snapshot();
        $repo = $this->gitStore()?->repo();
        $pull = new PullPolicy;
        $capacity = $pull->capacity($snapshot);
        $next = $pull->next($snapshot, 3);
        $counts = $snapshot->stageCounts();
        $work = fn (string $stage) => $pull->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === $stage), $stage);
        $blocked = $snapshot->cards(fn (Card $c) => $c->blocked() !== null && ! in_array($c->stage(), ['done', 'dropped'], true));
        $unpushed = $repo !== null && $repo->hasRemoteRef() ? $repo->ahead() : null;
        $driver = $repo?->mergeDriver();
        $origin = MainPush::of($this->paths(), $this->config())->known();
        $held = MergeQueue::heldCards($snapshot, $origin);

        return $this->json([
            'key' => $snapshot->key(),
            'head' => $repo?->head(),
            'unpushed' => $unpushed,
            'sync' => Sync::label($this->setting('sync', 'off'), $repo?->hasRemote() ?? false),
            'last_sync' => (new SyncStatus($this->paths()))->read(),
            'pending' => $store->pending(),
            'counts' => $counts,
            'capacity' => $capacity,
            'doing' => array_map(fn (Card $c) => $this->cardJson($c, $snapshot), $work('doing')),
            'review' => array_map(fn (Card $c) => $this->cardJson($c, $snapshot), $work('review')),
            'blocked' => array_map(fn (Card $c) => $c->id(), $blocked),
            'next' => array_map(fn (Card $c) => $c->id(), $next['cards']),
            'next_reason' => $next['reason'],
            'skipped' => $pull->skipped($snapshot),
            'merge_driver' => $driver !== null,
            // the merge queue: the lease, the cards in the order they merge, and why the held ones wait
            'merge' => [
                'lease' => $snapshot->mergeLease(),
                'queue' => array_map(fn (Card $c) => $c->id(), MergeQueue::cards($snapshot)),
                'held' => (object) $held,
            ],
        ]);
    }
}
