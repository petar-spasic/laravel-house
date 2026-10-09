<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Code\AgentRun;
use PetarSpasic\LaravelHouse\Kanban\Code\CloneFile;
use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Policy\MainRed;
use PetarSpasic\LaravelHouse\Kanban\Policy\MergeQueue;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
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
    public function lines(?string $session, ?string $transcript = null): array
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
        $blocked = $snapshot->cards(fn (Card $c) => $c->blocked() !== null && ! in_array($c->stage(), ['done', 'dropped'], true));
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
        $lines[] = "WIP {$capacity['used']}".($elsewhere > 0 ? " (+{$elsewhere} elsewhere)" : '').", review {$capacity['review']}/{$capacity['review_limit']}"
            .' · planning '.($counts['planning'] ?? 0).' · ready '.($counts['ready'] ?? 0).' · backlog '.($counts['backlog'] ?? 0).' · blocked '.count($blocked)
            .' · questions '.Questions::tally(Questions::pending($snapshot));
        if (($agents = $this->agents($runtime)) !== null) {
            $lines[] = $agents;
        }
        $lease = $snapshot->mergeLease();
        if ($lease !== null) {
            $lines[] = 'merge: '.MergeLease::describe($lease).', last beat '.$lease['beat'];
        }
        $origin = MainPush::of($this->paths, $this->config)->known();
        foreach (MainRed::open($snapshot, $origin) as $row) {
            $lines[] = MainRed::line($row);
        }
        foreach (array_filter($work('planning'), fn (Card $c) => $c->atWork()) as $card) {
            $lines[] = 'planning '.$this->short($card).': '.implode(', ', [...(Plan::madeUnderClaim($card) ? [Plan::current($card) ? 'planned, not yet moved to ready' : 'planned, then the card changed: its planner revises it'] : []),
                ...$this->flight($card, $runtime, 'kanban-planner'), ...$this->trouble($card, $runtime)]);
        }
        foreach ($work('doing') as $card) {
            $lines[] = 'doing  '.$this->short($card).': '.implode(', ', [...$this->flight($card, $runtime, 'kanban-worker'), ...$this->trouble($card, $runtime)]);
        }
        // the merge queue first, in the order it merges them: the card being merged, then by approval
        $queue = MergeQueue::cards($snapshot);
        usort($queue, fn (Card $a, Card $b) => ($b->id() === ($lease['card'] ?? null)) <=> ($a->id() === ($lease['card'] ?? null)));
        $ids = array_map(fn (Card $c) => $c->id(), $queue);
        $states = MergeQueue::states($snapshot, $origin);
        foreach ([...$queue, ...array_filter($work('review'), fn (Card $c) => ! in_array($c->id(), $ids, true))] as $card) {
            $parts = in_array($card->id(), $ids, true)
                ? [self::place($card, $snapshot, $this->paths, $origin, $states), ...($card->id() === ($lease['card'] ?? null) ? array_slice($this->flight($card, $runtime, 'kanban-merger'), 0, 1) : [])]
                : [is_string($card->work()['approved']['head'] ?? null) ? 'approved '.substr($card->work()['approved']['head'], 0, 7).', not merged' : 'awaiting verdict',
                    ...array_slice($this->flight($card, $runtime, 'kanban-evaluator'), 0, 1)];
            $lines[] = 'review '.$this->short($card).': '.implode(', ', array_filter([...$parts, ...$this->trouble($card, $runtime)], fn ($p) => $p !== 'no agent'));
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
        if (($rules = $this->bigRules()) !== []) {
            $lines[] = 'rules over 40 KB (prune them): '.implode(', ', $rules);
        }
        if (Findings::enabled($this->config) && ($pending = count(Findings::pending($snapshot))) > 0) {
            $lines[] = "upstream: {$pending} pending (`kanban upstream`)";
        }
        $lines[] = 'next: '.($next['cards'] === []
            ? 'none: '.$next['reason']
            : implode(', ', array_map(fn (Card $c) => $c->id().' '.Priority::short($c->priority()), $next['cards'])));
        if ($snapshot->cards(fn (Card $c) => $c->stage() === 'planning' && ! $c->atWork()) !== []) {
            $plan = $pull->nextPlanning($snapshot, 3);
            $waiting = $pull->waiting($snapshot);
            $lines[] = 'plan next: '.($plan['cards'] === []
                ? 'none: '.$plan['reason']
                : implode(', ', array_map(fn (Card $c) => $c->id().' '.Priority::short($c->priority()), $plan['cards'])))
                .($waiting === [] ? '' : '; waiting: '.implode('; ', array_map(fn (string $id, string $why) => "{$id} {$why}", array_keys(array_slice($waiting, 0, 3)), array_slice($waiting, 0, 3)))
                    .(count($waiting) > 3 ? '; '.(count($waiting) - 3).' more' : ''));
        }
        $skipped = $pull->skipped($snapshot);
        if ($skipped !== []) {
            $lines[] = 'skipped: '.implode('; ', array_map(fn (string $id, string $why) => "{$id} {$why}", array_keys(array_slice($skipped, 0, 5)), array_slice($skipped, 0, 5)))
                .(count($skipped) > 5 ? '; '.(count($skipped) - 5).' more (`kanban next -v`)' : '');
        }
        $lines[] = 'checks: '.implode(' · ', $this->checks($snapshot, $repo?->mergeDriver() !== null, $session, $transcript));

        return $lines;
    }

    /**
     * Where $card stands in the merge queue: `merging on host-a (Ana) since 08:00: conflicts in app.php` (the phase only when
     * this checkout merges it), `waits for main to be fixed (…)`, or `queued 2nd`; null when it is not in the queue.
     * $origin is main's tip as MainPush::known() gives it; $states what MergeQueue::states() gives for it, when the caller has it.
     *
     * @param  array<string, array{state: string, position?: int, waits?: string}>|null  $states
     */
    public static function place(Card $card, Snapshot $snapshot, Paths $paths, ?string $origin, ?array $states = null): ?string
    {
        $lease = $snapshot->mergeLease();
        if (($lease['card'] ?? null) === $card->id()) {
            $state = (new MergeState($paths))->read();
            $phase = ($state['lease'] ?? null) === $lease['id'] ? MergeState::turn($state) ?? $state['phase'] : null;

            return 'merging on '.preg_replace('/^.*@/', '', (string) $lease['by']).(isset($lease['who']) ? " ({$lease['who']})" : '')
                .' since '.substr((string) $lease['since'], 11, 5).($phase === null ? '' : ": {$phase}");
        }
        $state = ($states ?? MergeQueue::states($snapshot, $origin))[$card->id()] ?? [];
        $at = $state['position'] ?? 0;

        return match ($state['state'] ?? null) {
            'held' => $state['waits'],
            'queued' => 'queued '.$at.(in_array($at % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$at % 10] ?? 'th')),
            default => null,
        };
    }

    /**
     * Tracked `CLAUDE.md` files over 40 KB, biggest first: every agent that works there reads them whole, and Claude Code
     * warns about a `CLAUDE.md` past 40,000 characters.
     *
     * @return list<string>
     */
    private function bigRules(): array
    {
        $sizes = [];
        foreach (explode("\0", (new Git($this->paths->main))->attempt(['ls-files', '-z', '--', 'CLAUDE.md', '*/CLAUDE.md'])->out) as $file) {
            if ($file !== '' && ($size = (int) @filesize($this->paths->main.'/'.$file)) > 40 * 1024) {
                $sizes[$file] = $size;
            }
        }
        arsort($sizes);

        return array_map(fn (string $file, int $size) => $file.' '.intdiv($size, 1024).' KB', array_keys($sizes), $sizes);
    }

    /** `agents: 1 planner, 2 workers, 1 evaluator, 1 merger live; last 24h: 9 runs, 4.1M tokens, $3.20`, or null when there is neither. */
    private function agents(Runtime $runtime): ?string
    {
        $live = ['kanban-planner' => 0, 'kanban-worker' => 0, 'kanban-evaluator' => 0, 'kanban-merger' => 0];
        foreach ($runtime->agents() as $agent) {
            if (isset($live[$agent['agent_type'] ?? '']) && $runtime->state($agent) === 'live') {
                $live[$agent['agent_type']]++;
            }
        }
        $runs = self::runs($this->paths, gmdate('Y-m-d\TH:i:s', time() - 86400));
        if (array_sum($live) === 0 && $runs === []) {
            return null;
        }
        $plural = fn (int $n, string $word) => "{$n} {$word}".($n === 1 ? '' : 's');

        return 'agents: '.($live['kanban-planner'] > 0 ? $plural($live['kanban-planner'], 'planner').', ' : '')
            .$plural($live['kanban-worker'], 'worker').', '.$plural($live['kanban-evaluator'], 'evaluator')
            .($live['kanban-merger'] > 0 ? ', '.$plural($live['kanban-merger'], 'merger') : '').' live'
            .($runs === [] ? '' : '; last 24h: '.$plural(count($runs), 'run').', '.self::tokens(array_sum(array_column($runs, 'tokens'))).' tokens, $'
                .number_format(array_sum(array_column($runs, 'cost_usd')), 2));
    }

    /**
     * The agent runs `kanban run` logged as ended since $since (runs.jsonl).
     *
     * @return list<array{tokens: int, cost_usd: float}>
     */
    public static function runs(Paths $paths, string $since): array
    {
        $runs = [];
        foreach (AgentRun::history($paths) as $run) {
            if ((string) ($run['ended'] ?? '') >= $since) {
                $runs[] = ['tokens' => (int) ($run['tokens'] ?? 0), 'cost_usd' => (float) ($run['cost_usd'] ?? 0)];
            }
        }

        return $runs;
    }

    public static function tokens(float $n): string
    {
        return $n >= 1e6 ? round($n / 1e6, 1).'M' : ($n >= 1e3 ? round($n / 1e3).'k' : (string) (int) $n);
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
        if (is_string($worktree) && $card->atWork() && $card->stage() !== 'review' && $card->host() === gethostname() && ! is_dir($this->paths->main.'/'.$worktree)) {
            $parts[] = "worktree missing (`kanban start {$card->id()}` resumes the start)";
        }
        if (is_string($worktree) && self::merging(str_starts_with($worktree, '/') ? $worktree : $this->paths->main.'/'.$worktree)) {
            $parts[] = 'merge in progress';
        }
        foreach (['plan', 'report', 'verdict'] as $kind) {
            if (($refused = $runtime->refusal($card->id(), $kind)) !== null) {
                $parts[] = "{$kind} staged, not applied: ".mb_strimwidth(rtrim((string) strtok($refused['reason'], "\n"), ':'), 0, 200, '…');
            }
        }

        return $parts;
    }

    /** A card's MERGE_HEAD, in its clone's `.git` or found through a linked worktree's `.git` file, without running git. */
    private static function merging(string $worktree): bool
    {
        if (is_dir($worktree.'/.git')) {
            return is_file($worktree.'/.git/MERGE_HEAD');
        }
        $link = CloneFile::read($worktree, '.git');
        if (! is_string($link) || preg_match('/^gitdir: (.+)$/m', $link, $m) !== 1) {
            return false;
        }
        $gitdir = trim($m[1]);

        return is_file(($gitdir[0] === '/' ? $gitdir : $worktree.'/'.$gitdir).'/MERGE_HEAD');
    }

    /** @return list<string> */
    private function checks(Snapshot $snapshot, bool $driver, ?string $session, ?string $transcript): array
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
            'lease: '.(new Lease($this->paths))->describe($session, $transcript),
        ];
    }

    /**
     * Card worktrees (named after a card id) whose card is not at work in that worktree.
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
            if (! $card->atWork() || $this->paths->relative($dir) !== $worktree) {
                $orphans[] = basename($dir);
            }
        }

        return $orphans;
    }
}
