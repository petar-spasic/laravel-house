<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Code\MainCheck;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Shape;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\GitStore;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\SyncStatus;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Support\Sync;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;

/** The factual board brief printed by `status` and SessionStart. */
final class Brief
{
    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Store $store,
        private readonly Paths $paths,
        private readonly array $config,
    ) {}

    /** @return list<string> */
    public function lines(?string $session): array
    {
        $snapshot = $this->store->snapshot();
        $runtime = new Runtime($this->paths, $snapshot->staleMinutes());
        $repo = $this->store instanceof GitStore ? $this->store->repo() : null;
        $pull = new PullPolicy;
        $capacity = $pull->capacity($snapshot);
        $next = $pull->next($snapshot, 3);
        $counts = [];
        foreach ($snapshot->cards as $card) {
            $counts[$card->stage()] = ($counts[$card->stage()] ?? 0) + 1;
        }
        $work = fn (string $stage) => $pull->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === $stage), $stage);
        $blocked = $snapshot->cards(fn (Card $c) => $c->blocked() !== null);
        $unpushed = $repo !== null && $repo->hasRemoteRef() ? $repo->ahead() : null;

        $lines = [];
        $lines[] = "Kanban {$snapshot->key()}: branch kanban @".($repo?->head() ?? '-').', '
            .($unpushed === null ? 'not published' : "{$unpushed} unpushed").', sync '.Sync::label($this->config['sync'] ?? 'off', $repo?->hasRemote() ?? false).', '.gmdate('Y-m-d H:i').'Z';
        if ($unpushed !== null && ! ($this->store instanceof GitStore && $this->store->syncOn())) {
            $lines[] = Sync::PUBLISHED_BUT_OFF;
        }
        if (($sync = (new SyncStatus($this->paths))->line()) !== null) {
            $lines[] = $sync;
        }
        $elsewhere = $capacity['doing'] - $capacity['here'];
        $lines[] = "WIP doing {$capacity['here']}/{$capacity['max_parallel']}".($elsewhere > 0 ? " (+{$elsewhere} elsewhere)" : '').", review {$capacity['review']}/{$capacity['review_limit']}"
            .' · ready '.($counts['ready'] ?? 0).' · backlog '.($counts['backlog'] ?? 0).' · blocked '.count($blocked)
            .' · questions '.count(array_filter($blocked, fn (Card $c) => $c->asks()));
        if (($red = (new MainCheck($this->paths))->red()) !== null) {
            $lines[] = 'main red since '.substr((string) $red['sha'], 0, 7)." ({$red['after']} merged): `{$red['command']}` fails; finish waits for it ("
                .($red['card'] ?? 'no card').')';
        }
        foreach ($work('doing') as $card) {
            $lines[] = 'doing  '.$this->short($card).': '.implode(', ', [...$this->flight($card, $runtime, 'kanban-worker'), ...$this->trouble($card, $runtime)]);
        }
        // Approved cards first, oldest approval first: the order main finishes them in.
        $review = $work('review');
        $approved = array_values(array_filter($review, fn (Card $c) => is_string($c->work()['approved']['at'] ?? null)));
        usort($approved, fn (Card $a, Card $b) => strcmp($a->work()['approved']['at'], $b->work()['approved']['at']));
        foreach ([...$approved, ...array_filter($review, fn (Card $c) => ! in_array($c, $approved, true))] as $card) {
            $approved = $card->work()['approved']['head'] ?? null;
            $parts = [$approved ? 'approved '.substr($approved, 0, 7).', not merged' : 'awaiting verdict'];
            $parts = array_merge($parts, array_slice($this->flight($card, $runtime, 'kanban-evaluator'), 0, 1), $this->trouble($card, $runtime));
            $lines[] = 'review '.$this->short($card).': '.implode(', ', array_filter($parts, fn ($p) => $p !== 'no agent'));
        }
        foreach (array_slice($blocked, 0, 10) as $card) {
            $lines[] = "blocked {$card->id()} {$card->title()}: \"".mb_strimwidth((string) $card->blocked(), 0, 160, '…').'"';
        }
        if (count($blocked) > 10) {
            $lines[] = 'blocked: '.(count($blocked) - 10).' more (`kanban list`)';
        }
        if (($hubs = Shape::hubs($snapshot)) !== []) {
            $lines[] = 'hubs: '.implode(', ', array_map(fn (string $id) => "{$id} blocks ".count($hubs[$id]), array_keys($hubs)));
        }
        if (Findings::enabled($this->config) && ($pending = count(Findings::pending($snapshot))) > 0) {
            $lines[] = "upstream: {$pending} pending (`kanban upstream`)";
        }
        $lines[] = 'next: '.($next['cards'] === []
            ? 'none: '.$next['reason']
            : implode(', ', array_map(fn (Card $c) => $c->id().' '.Priority::short($c->priority()), $next['cards'])));
        $skipped = $pull->skipped($snapshot);
        if ($skipped !== []) {
            $lines[] = 'skipped: '.implode('; ', array_map(fn (string $id, string $why) => "{$id} {$why}", array_keys(array_slice($skipped, 0, 5)), array_slice($skipped, 0, 5)))
                .(count($skipped) > 5 ? '; '.(count($skipped) - 5).' more (`kanban next -v`)' : '');
        }
        $lines[] = 'checks: '.implode(' · ', $this->checks($snapshot, $repo?->mergeDriver() !== null, $session));

        return $lines;
    }

    private function short(Card $card): string
    {
        return "{$card->id()} ".Priority::short($card->priority())." {$card->board} {$card->title()}";
    }

    /**
     * `worker a4d2 live 4m`, `wt key-7k2m9q`, the stack URL.
     *
     * @return list<string>
     */
    private function flight(Card $card, Runtime $runtime, string $type): array
    {
        $agent = $runtime->agentFor($card->id(), $type);
        $parts = [];
        if ($agent === null && ($host = $card->host()) !== null && $host !== (string) gethostname()) {
            $parts[] = "on {$host}";
        } elseif ($agent === null) {
            $parts[] = 'no agent';
        } else {
            $state = $runtime->state($agent);
            $since = $state === 'live' && ! empty($agent['bound_at']) ? Clock::seconds((string) $agent['bound_at']) : time() - (int) $agent['beat'];
            $parts[] = str_replace('kanban-', '', $type).' '.substr((string) $agent['agent_id'], 0, 4).' '.$state
                .($state === 'stopped' ? '' : ' '.Clock::human($since));
        }
        if (is_string($worktree = $card->work()['worktree'] ?? null)) {
            $parts[] = 'wt '.basename($worktree);
        }
        if (is_string($url = $card->work()['stack']['url'] ?? null)) {
            $parts[] = $url;
        }

        return $parts;
    }

    /**
     * A worktree a start cut short never made, a merge left in progress in the card's worktree, and staged items a hook or `apply` refused, with the first line of why.
     *
     * @return list<string>
     */
    private function trouble(Card $card, Runtime $runtime): array
    {
        $parts = [];
        $worktree = $card->work()['worktree'] ?? null;
        if (is_string($worktree) && $card->stage() === 'doing' && $card->host() === gethostname() && ! is_dir($this->paths->main.'/'.$worktree)) {
            $parts[] = "worktree missing (`kanban start {$card->id()}` resumes the start)";
        }
        if (is_string($worktree) && self::merging(str_starts_with($worktree, '/') ? $worktree : $this->paths->main.'/'.$worktree)) {
            $parts[] = 'merge in progress';
        }
        foreach (['report', 'verdict'] as $kind) {
            if (($refused = $runtime->refusal($card->id(), $kind)) !== null) {
                $parts[] = "{$kind} staged, not applied: ".mb_strimwidth(rtrim((string) strtok($refused['reason'], "\n"), ':'), 0, 200, '…');
            }
        }

        return $parts;
    }

    /** A linked worktree's MERGE_HEAD, found through its `.git` file without running git. */
    private static function merging(string $worktree): bool
    {
        $link = @file_get_contents($worktree.'/.git');
        if (! is_string($link) || preg_match('/^gitdir: (.+)$/m', $link, $m) !== 1) {
            return false;
        }
        $gitdir = trim($m[1]);

        return is_file(($gitdir[0] === '/' ? $gitdir : $worktree.'/'.$gitdir).'/MERGE_HEAD');
    }

    /** @return list<string> */
    private function checks(Snapshot $snapshot, bool $driver, ?string $session): array
    {
        $guard = dirname(__DIR__, 3).'/bin/kanban-guard';
        $hooksPath = (new Git($this->paths->main))->line(['config', '--get', 'core.hooksPath']);
        $orphans = $this->orphans($snapshot);

        return [
            'merge driver '.($driver ? 'ok' : 'missing (run `kanban attach`)'),
            'journal '.$this->store->pending(),
            'guard '.(is_executable($guard) ? 'ok' : 'not executable ('.$guard.')'),
            'hooksPath '.($hooksPath === Bootstrap::HOOKS_PATH ? 'ok' : ($hooksPath === null || $hooksPath === '' ? 'unset' : $hooksPath)),
            count($orphans).' orphan worktrees'.($orphans === [] ? '' : ' ('.implode(', ', $orphans).')'),
            'lease: '.(new Lease($this->paths))->describe($session),
        ];
    }

    /**
     * Card worktrees (named after a card id) whose card is not in doing/review with that worktree.
     *
     * @return list<string>
     */
    private function orphans(Snapshot $snapshot): array
    {
        $orphans = [];
        foreach (glob($this->paths->worktrees().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $card = $snapshot->card(strtoupper(basename($dir)));
            if ($card === null) {
                continue;
            }
            $worktree = (string) ($card->work()['worktree'] ?? '');
            if (! in_array($card->stage(), ['doing', 'review'], true) || $this->paths->relative($dir) !== $worktree) {
                $orphans[] = basename($dir);
            }
        }

        return $orphans;
    }
}
