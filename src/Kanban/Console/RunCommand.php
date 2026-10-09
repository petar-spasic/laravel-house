<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\AgentRun;
use PetarSpasic\LaravelHouse\Kanban\Code\CloneFile;
use PetarSpasic\LaravelHouse\Kanban\Code\MainCheckout;
use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeBeat;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeClone;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeStep;
use PetarSpasic\LaravelHouse\Kanban\Code\PortRegistry;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\MergeQueue;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeLease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The board's driver: the run loop in code. Each pass reaps ended agents, drives the merge queue (one detached `finish`
 * at a time, MergeRun, and the merger when it needs judgement), starts evaluators, workers and planners headless
 * (AgentRun), parks question cards in backlog, moves planned cards to ready and fills the free slots, workers first and
 * planners on what they leave; every board change is a
 * `vendor/bin/kanban` command run as the orchestrating session (KANBAN_SESSION, else `run:<host>`), which holds the
 * lease. What needs the orchestrator's judgment is a notice; `--until-attention` returns with them. One run drives a
 * checkout at a time (`run.pid`); `kanban drain` (`run.drain`) makes it, and the runs after it, drain until one has.
 */
#[AsCommand(name: 'kanban:run')]
class RunCommand extends Command
{
    protected $signature = 'kanban:run
        {--once : One pass, then exit}
        {--until-attention : Return when something needs the orchestrator, or after --timeout}
        {--timeout=1500 : Seconds --until-attention runs at most}
        {--drain : Start no new card, only parked work; return once none is in flight}';

    protected $description = 'Drive the board until stopped: plan and start cards, run their agents headless, evaluate, merge, park questions';

    private const PASS_SECONDS = 15;

    private const PAUSE_SECONDS = 900;

    private const STRIKES = 3;

    /** The runtime file `kanban drain` leaves: every run drains until one has. */
    public const DRAIN = 'run.drain';

    private const PID = 'run.pid';

    private const LOCK = 'run.lock';

    private const LEAKED_SECONDS = 600;

    /** How long a merge that failed outright (exit 1, 2, 4, 7, 9) waits before the queue tries again. */
    private const MERGE_RETRY_SECONDS = 300;

    /** How often the main checkout follows the remote's main while no merge runs here. */
    private const FOLLOW_SECONDS = 120;

    /** The merger's launches that end without a result before its card is blocked. */
    private const MERGER_RUNS = 3;

    private Worktrees $worktrees;

    /** Why the stack cap stopped starts in this pass, for the idle notice. */
    private ?string $capped = null;

    private string $session;

    private AgentRun $agents;

    /** Cards whose start was refused, skipped for the rest of this run: id => why. @var array<string, string> */
    private array $held = [];

    /** @var list<string> what needs the orchestrator, from this pass */
    private array $notices = [];

    private bool $published = false;

    private ?MergeStep $step = null;

    /** What MergeStep::queue saw in this pass: the queue's next card here and the lease as observed. @var array<string, mixed> */
    private array $queue = [];

    /** Done cards whose leftovers here were tidied in this run, once each. @var array<string, true> */
    private array $tidied = [];

    protected function perform(): int
    {
        $this->requireMainCheckout('run');
        $this->agents = new AgentRun($this->paths(), $this->config());
        $this->worktrees = new Worktrees($this->paths(), $this->config());
        if (($why = $this->agents->unusable()) !== null) {
            throw new PolicyRefused($why);
        }
        // one run per checkout: the lock is the truth, freed by the system however the run ends (Ctrl-C, a kill); close-on-exec,
        // so the agents it detaches never hold it. run.pid only names the process
        $this->paths()->ensureRuntime();
        $lock = fopen($this->paths()->runtime(self::LOCK), 'ce');
        // another process may be looking at the lock (`running()`, a moment's shared hold): a short retry
        for ($try = 0; $lock !== false && ! ($locked = flock($lock, LOCK_EX | LOCK_NB)) && $try < 20; $try++) {
            usleep(100_000);
        }
        if ($lock === false || ! ($locked ?? false)) {
            $pid = (int) @file_get_contents($this->paths()->runtime(self::PID));
            throw new PolicyRefused('kanban run already drives this checkout'.($pid > 0 ? " (pid {$pid})" : '').($this->option('drain') ? ': `kanban drain` makes it drain' : ''));
        }
        try {
            file_put_contents($this->paths()->runtime(self::PID), (string) getmypid());
            $own = getenv('KANBAN_SESSION');
            $this->session = is_string($own) && $own !== '' ? $own : 'run:'.gethostname();
            $lease = new Lease($this->paths());
            $this->session === $own ? $lease->acquire(new Actor('main', $this->session)) : $lease->takeover(new Actor('main', $this->session));

            return $this->loop();
        } finally {
            @unlink($this->paths()->runtime(self::PID));
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The pid of the run driving this checkout (0 when it is not known), or null when none does. */
    public static function running(Paths $paths): ?int
    {
        $lock = @fopen($paths->runtime(self::LOCK), 'ce');
        if ($lock === false) {
            return null;
        }
        try {
            if (flock($lock, LOCK_SH | LOCK_NB)) {
                flock($lock, LOCK_UN);

                return null;
            }

            return (int) @file_get_contents($paths->runtime(self::PID));
        } finally {
            fclose($lock);
        }
    }

    private function loop(): int
    {
        $this->log("driving the board as {$this->session}".match (true) {
            (bool) $this->option('drain') => ', draining',
            $this->draining() => ', draining: `kanban drain` asked for it (`kanban drain --off` runs normally)',
            default => '',
        });
        $until = time() + (int) $this->option('timeout');
        while (true) {
            $this->notices = [];
            try {
                $this->pass();
            } catch (LockTimeout $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->notice('kanban run failed: '.get_class($e).': '.$e->getMessage());
            }
            if ($this->draining() && $this->drained()) {
                @unlink($this->paths()->runtime(self::DRAIN));
                $this->log('drained: no card in flight');

                return self::SUCCESS;
            }
            if ($this->option('until-attention') && $this->notices !== []) {
                $this->say('attention:');
                foreach ($this->notices as $notice) {
                    $this->say('  '.$notice);
                }

                return self::SUCCESS;
            }
            if ($this->option('once')) {
                return self::SUCCESS;
            }
            if ($this->option('until-attention') && time() >= $until) {
                $this->log('nothing needs you: run it again');

                return self::SUCCESS;
            }
            sleep(self::PASS_SECONDS);
        }
    }

    private function pass(): void
    {
        // the pass works on what origin holds: cards readied on other machines, a claim of this checkout that landed
        // although its start failed. No session or UI may be pulling for this machine
        $this->gitStore()?->maybeSync(wait: true);
        $state = $this->state();
        // ended() has logged and forgotten every run: one that fails here must not take the others' outcome with it
        foreach ($this->agents->ended() as $run) {
            try {
                $state = $this->reaped($run, $state);
            } catch (LockTimeout $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->notice("{$run['card']} ".str_replace('kanban-', '', (string) $run['type']).' ended, not counted: '.$e->getMessage());
            }
        }
        $this->kanban(['apply', '--all']);
        $paused = ($state['paused_until'] ?? 0) > time();
        $running = array_column($this->agents->running(), 'card');
        $acted = $this->merge($paused);

        $snapshot = $this->store()->snapshot();
        foreach ($this->local($snapshot, fn (Card $c) => $c->stage() === 'review' && ! isset($c->work()['approved'])) as $card) {
            if (! $paused && ! in_array($card->id(), $running, true)) {
                $acted = $this->evaluate($card->id()) || $acted;
            }
        }

        $snapshot = $this->store()->snapshot();
        foreach ($this->unstarted($snapshot) as $card) {
            if (! $paused && ! in_array($card->id(), $running, true) && ($this->stackFull() === null || $this->holdsSlot($card))) {
                $acted = $this->resumeStart($card) || $acted;
            }
        }

        $snapshot = $this->store()->snapshot();
        $runtime = new Runtime($this->paths(), $snapshot->staleMinutes());
        // the agents launched so far in this pass run too
        $running = array_column($this->agents->running(), 'card');
        foreach ($this->local($snapshot, fn (Card $c) => $c->stage() === 'doing') as $card) {
            if (in_array($card->id(), $running, true)) {
                continue;
            }
            if ($card->asks()) {
                $stop = $this->kanban(['stop', $card->id(), '--to=backlog']);
                $stop->isSuccessful() ? $this->notice("{$card->id()} parked in backlog: {$card->blocked()}") : $this->block($card->id(), $stop);
                $acted = true;
            } elseif (($loop = $this->mergeLoop($card) ?? $this->rejectLoop($card)) !== null) {
                // a third round on what failed twice is no better: the orchestrator judges it, and watch() reports it.
                // Once per verdict or send-back, kept only once the block is written
                [$why, $key, $mark] = $loop;
                if ($this->kanban(['set', $card->id(), "blocked={$why}"])->isSuccessful()) {
                    $kept = $this->state();
                    $kept[$key][$card->id()] = $mark;
                    $this->saveState($kept);
                }
                $acted = true;
            } elseif (! $paused) {
                $resume = $this->resumable($runtime, $card, AgentRun::WORKER);
                // a fix that unblocked the card may have landed on main, and a card the merge sent back meets main's conflict
                // in its clone, whichever worker takes it; 5: the worker concludes the conflict first. Not into uncommitted
                // edits (a session that ended mid-work): the worker commits them first (its stop gate), and the next round
                // merges. Untracked files stay out of a merge, and the stop gate catches one folded into it
                $merge = $resume !== null || self::lastMove($card) === 'merge';
                $dirty = $this->changed($card);
                if ($merge && ! $dirty && ! in_array(($refresh = $this->kanban(['refresh', $card->id()]))->getExitCode(), [0, 5], true)) {
                    // an agent of the card not started headless still runs: it is waited for
                    str_contains($refresh->getErrorOutput(), RefreshCommand::LIVE) || $this->block($card->id(), $refresh);

                    continue;
                }
                if (($session = $this->launch($card, AgentRun::WORKER, $resume)) === null) {
                    continue;
                }
                $this->log("{$card->id()} worker ".substr($session, 0, 8).($resume === null ? ' launched ' : ' resumed ').$this->agents->pins($session).($merge && $dirty ? ', main not merged: uncommitted changes in its clone' : ''));
                $acted = true;
            }
        }

        $snapshot = $this->store()->snapshot();
        $running = array_column($this->agents->running(), 'card');
        foreach ($this->local($snapshot, fn (Card $c) => $c->stage() === 'planning' && $c->atWork()) as $card) {
            if (in_array($card->id(), $running, true) || $this->cutShort($card)) {
                continue;
            }
            if ($card->asks()) {
                $stop = $this->kanban(['stop', $card->id(), '--to=backlog']);
                $stop->isSuccessful() ? $this->notice("{$card->id()} parked in backlog: {$card->blocked()}") : $this->block($card->id(), $stop);
                $acted = true;
            } elseif (Plan::madeUnderClaim($card) && Plan::current($card)) {
                $ready = $this->kanban(['stop', $card->id(), '--to=ready']);
                $ready->isSuccessful() ? $this->log("{$card->id()} planned: ready") : $this->block($card->id(), $ready);
                $acted = true;
            } elseif (! $paused) {
                // no plan yet, or one the card has outgrown: its planner (re)writes it, on main as it is now
                $resume = $this->resumable($runtime, $card, AgentRun::PLANNER);
                if ($resume !== null && ! ($refresh = $this->kanban(['refresh', $card->id()]))->isSuccessful()) {
                    str_contains($refresh->getErrorOutput(), RefreshCommand::LIVE) || $this->block($card->id(), $refresh);

                    continue;
                }
                if (($session = $this->launch($card, AgentRun::PLANNER, $resume)) === null) {
                    continue;
                }
                $this->log("{$card->id()} planner ".substr($session, 0, 8).($resume === null ? ' launched ' : ' resumed ').$this->agents->pins($session));
                $acted = true;
            }
        }

        if (! $paused) {
            // a drain still starts parked work (a card back from its question, its branch kept): it is in flight. A card the
            // main session or the owner stopped stays put
            $draining = $this->draining();
            $draining || $this->promote();
            $this->capped = null;
            foreach ((new PullPolicy)->next($this->store()->snapshot(), 99, skip: array_keys($this->held))['cards'] as $card) {
                if (! $this->room() || isset($this->held[$card->id()]) || ($draining && ! self::inFlight($card))) {
                    if ($this->capped !== null) {
                        break;
                    }

                    continue;
                }
                $acted = $this->begin($card) || $acted;
            }
            // planners take the slots workers leave; a drain plans only parked work, which is in flight
            foreach ($this->capped !== null ? [] : (new PullPolicy)->nextPlanning($this->store()->snapshot(), 99, skip: array_keys($this->held))['cards'] as $card) {
                if (! $this->room()) {
                    break;
                }
                if (! isset($this->held[$card->id()]) && (! $draining || self::inFlight($card))) {
                    $acted = $this->begin($card) || $acted;
                }
            }
        }

        if ($acted || $this->agents->running() !== []) {
            $this->published = false;
        } elseif (! $this->published) {
            $this->published = true;
            $this->log('idle: '.strtok(trim($this->kanban(['sync'])->getOutput()) ?: 'synced', "\n"));
            // approved cards that wait (main red, another machine's card first) are not for the orchestrator to start
            $waiting = count($this->queued($this->store()->snapshot()));
            match (true) {
                $this->draining() => null,
                $waiting > 0 => $this->log("idle: {$waiting} approved cards wait for the merge queue"),
                default => $this->notice('idle: '.($this->capped !== null ? "no card can start: {$this->capped}" : 'no agent runs and no card can start: plan or promote cards')),
            };
        }
        $this->watch($state);
    }

    /**
     * The merge queue's part of a pass: what the last detached `finish` ended with, then the merge in flight here driven
     * on (its merger launched while it needs judgement, aborted once its card left the queue), else this machine's next
     * card in the queue started, a done card's leftovers tidied, or the main checkout brought to the remote's main. One
     * `finish` runs at a time (MergeRun); this run never clears merge.json itself. Whether a merge is in flight (a
     * follow is none).
     */
    private function merge(bool $paused): bool
    {
        // what the queue looked like in an earlier pass is not what it looks like now
        $this->queue = [];
        $run = new MergeRun($this->paths());
        if ($run->alive()) {
            return $run->card() !== 'follow';
        }
        // read before any start, which forgets it
        if (($ended = $run->ended()) !== null) {
            $this->mergeEnded($ended);
        }
        $merge = (new MergeState($this->paths()))->read();
        $why = $this->cannotMerge();
        // a push in flight is settled all the same: `finish` asks origin first, which needs no suite
        if ($why !== null && ($merge['phase'] ?? null) !== MergeState::PUSHING) {
            $this->mergeNotice($merge === null ? $why : "{$why}; the merge of {$merge['card']} waits here until then (`vendor/bin/kanban finish {$merge['card']} --abort` ends it)");
            $merge === null && $this->follow($run);

            return false;
        }
        $why === null && $this->forgetNotices('/^('.preg_quote(MergeStep::NO_SUITE, '/').'|'.preg_quote(MergeStep::NO_STACK, '/').')/');
        $snapshot = $this->store()->snapshot();
        $this->queue = $this->step()->queue($snapshot);
        if ($merge !== null && in_array($merge['phase'], [MergeState::CONFLICT, MergeState::RED], true) && $this->mergeLease()->mine($snapshot)) {
            return $this->merger($merge, $paused);
        }
        $waits = ($this->state()['merge_retry_at'] ?? 0) > time();
        $env = ['KANBAN_SESSION' => $this->session];
        if ($merge !== null) {
            // finish goes on from merge.json: a push to settle, a result applied, a lease lost or to give back
            $waits || $run->start(['finish', (string) $merge['card']], $env);

            return ! $waits;
        }
        $lease = $snapshot->mergeLease();
        if (! $paused && ! $waits && ($card = $this->queue['card']) !== null && ($lease === null || $this->queue['expired'])) {
            $run->start(['finish', $card->id()], $env);
            $this->saveState(['merge_follow_at' => time()] + $this->state());
            $this->log("{$card->id()} merging");

            return true;
        }
        if (! $waits && ($done = $this->leftover($snapshot)) !== null) {
            $this->tidied[$done] = true;
            $run->start(['finish', $done], $env);

            return true;
        }
        if ($this->follow($run)) {
            return false;
        }
        if ($this->queue['card'] === null && $this->queued($snapshot) === []) {
            (new MergeClone($this->paths(), $this->config()))->down();
        }

        return false;
    }

    /** Why the queue merges nothing now (MergeStep::refusal), from main's config/kanban.php as it is, not as this run started. */
    private function cannotMerge(): ?string
    {
        try {
            $config = Standalone::config($this->paths()->main);
        } catch (Throwable) {
            $config = $this->config();
        }

        return MergeStep::refusal($config, $this->paths()->main);
    }

    /**
     * `finish --follow` when FOLLOW_SECONDS have passed since the last merge or follow here: card clones take main from
     * this checkout, so it follows what the one merge at a time pushed. Whether it started one.
     */
    private function follow(MergeRun $run): bool
    {
        $state = $this->state();
        if (time() - (int) ($state['merge_follow_at'] ?? 0) < self::FOLLOW_SECONDS || ! MainPush::of($this->paths(), $this->config())->hasRemote()) {
            return false;
        }
        $this->saveState(['merge_follow_at' => time()] + $state);
        $run->start(['finish', '--follow'], ['KANBAN_SESSION' => $this->session]);

        return true;
    }

    /**
     * The merge in flight here waits on the merger (a conflict, a red check): one at work is left to it; else it is
     * launched, unless the card left the queue or a usage limit pauses the agents (then the merge is aborted and the lease
     * goes back).
     *
     * @param  array<string, mixed>  $merge  merge.json
     */
    private function merger(array $merge, bool $paused): bool
    {
        $id = (string) $merge['card'];
        $env = ['KANBAN_SESSION' => $this->session];
        $beat = new MergeBeat($this->paths(), $this->mergeLease(), $this->agents);
        $why = $this->step()->left($merge);
        if ($why === null && $beat->mergerAlive($id)) {
            $beat->ensure((string) $merge['lease'], $env);

            return true;
        }
        $why ??= $paused ? 'paused: usage limit' : null;
        // read again once no merger runs: one that ended while this pass looked at the queue applied its result
        $merge = (new MergeState($this->paths()))->read();
        if ($merge === null || (string) $merge['card'] !== $id || ! in_array($merge['phase'], [MergeState::CONFLICT, MergeState::RED], true)) {
            return true;
        }
        if ($why === null && (int) ($merge['merger_runs'] ?? 0) >= self::MERGER_RUNS) {
            $why = 'merger stopped without a result '.self::MERGER_RUNS.'×';
            $this->kanban(['set', $id, "blocked={$why}"]);
        }
        if ($why !== null) {
            (new MergeRun($this->paths()))->start(['finish', $id, '--abort'], $env);
            $this->log("merge of {$id} aborted: {$why}");

            return true;
        }
        if (! (new MergeClone($this->paths(), $this->config()))->running()) {
            // its shell runs in the merge stack: `finish` brings it up (after a reboot), then the merger is launched
            ($this->state()['merge_retry_at'] ?? 0) > time() || (new MergeRun($this->paths()))->start(['finish', $id], $env);

            return true;
        }
        if (($session = $this->launch($this->store()->snapshot()->resolve($id), AgentRun::MERGER, null, $this->paths()->mergeClone())) === null) {
            return false;
        }
        $beat->ensure((string) $merge['lease'], $env);
        $this->log("{$id} merger ".substr($session, 0, 8).' launched '.$this->agents->pins($session).': '.MergeState::turn($merge));

        return true;
    }

    /**
     * What a detached `finish` ended with: the merge and what rebuilding main's stack did, logged; what failed after a
     * merge (exit 10) a notice, never a block on the card; a failure outright a notice once while it lasts, and the queue
     * waits MERGE_RETRY_SECONDS (not for a follow); a card refused while still queued is blocked for the orchestrator.
     *
     * @param  array{card: string, exit: int, out: string, err: string}  $ended
     */
    private function mergeEnded(array $ended): void
    {
        $id = $ended['card'];
        $exit = $ended['exit'];
        $follow = $id === 'follow';
        foreach (explode("\n", trim($ended['out'])) as $line) {
            if (str_starts_with($line, "{$id} ") || str_starts_with($line, "{$id}:") || preg_match('/^(merged |rebuil|warning:|main checkout)/', $line) === 1) {
                $this->log($line);
            }
        }
        $errors = array_values(array_filter(explode("\n", rtrim($ended['err'])), fn (string $l) => trim($l) !== '' && ! str_starts_with($l, '  ')));
        if (in_array($exit, [0, FinishCommand::STEP_FAILED], true)) {
            $merged = str_contains($ended['out'], "merged {$id} ");
            // a teardown that failed fails again at once: a later run tidies what it left
            ($exit === FinishCommand::STEP_FAILED && ! $follow) && $this->tidied[$id] = true;
            $merged && $this->forgetNotices('/^'.preg_quote($id, '/').'\b/');
            ($exit === 0 && ! $follow) && $this->forgetNotices('/^'.preg_quote($id, '/').' done; its leftovers/');
            ($exit === 0 && ($follow || $merged)) && $this->forgetNotices('/^main checkout/');
            foreach ($errors as $line) {
                match (true) {
                    $exit === 0 => $this->log(($follow ? 'main checkout' : "{$id} finish").": {$line}"),
                    $follow => $this->checkoutNotice($line),
                    $merged => $this->notice("{$id} merged, then: {$line}"),
                    // a card done before this finish (a tidy, or found on main already): what it could not remove, once while it lasts
                    default => $this->mergeNotice("{$id} done; its leftovers here: {$line}"),
                };
            }

            return;
        }
        // what the main checkout met before the merge's own failure is raised on its own
        $checkout = preg_grep(MainCheckout::FAULT, $errors) ?: [];
        array_map($this->checkoutNotice(...), $checkout);
        $first = array_values(array_diff_key($errors, $checkout))[0] ?? "exit {$exit}";
        if ($exit === FinishCommand::WAITING) {
            // a wait on the main checkout is its notice
            $checkout === [] && $this->mergeWaits($first);
        } elseif (in_array($exit, [LostClaim::EXIT, LockTimeout::EXIT], true)) {
            $this->log("{$id}: {$first}");
        } elseif ($follow) {
            // a follow that failed holds no merge back
            $this->checkoutNotice("main checkout not brought to the remote's main: {$first}");
        } elseif ($exit === PolicyRefused::EXIT) {
            $this->refused($id, $first);
        } elseif (! in_array($exit, [FinishCommand::MERGER, FinishCommand::RETURNED], true)) {
            $this->mergeFailed(str_starts_with($first, $id) ? $first : "{$id} merge failed: {$first}");
        }
    }

    /** What the main checkout met: it follows every FOLLOW_SECONDS, and what keeps failing there is raised once until a follow or merge here succeeds. */
    private function checkoutNotice(string $line): void
    {
        $this->mergeNotice(str_starts_with($line, 'main checkout') ? $line : "main checkout: {$line}");
    }

    /** A card `finish` refused: blocked while it is still queued (its branch gone from here, say), else only logged; the queue's own refusal is a notice. */
    private function refused(string $id, string $why): void
    {
        if (in_array($why, [MergeStep::NO_SUITE, MergeStep::NO_STACK], true)) {
            $this->mergeNotice($why);

            return;
        }
        if (in_array($id, array_map(fn (Card $c) => $c->id(), MergeQueue::cards($this->store()->snapshot())), true)) {
            $this->kanban(['set', $id, 'blocked=kanban run: '.mb_substr($why, 0, 400)]);

            return;
        }
        $this->log("{$id} not merged: {$why}");
    }

    private function mergeFailed(string $line): void
    {
        $this->mergeNotice($line);
        $this->saveState(['merge_retry_at' => time() + self::MERGE_RETRY_SECONDS] + $this->state());
    }

    /**
     * A notice once while its cause lasts, whatever commits it names (run.json `merge_notices`, the last ones kept; one
     * is forgotten once its cause cleared, so a cause that comes back is raised again).
     */
    private function mergeNotice(string $line): void
    {
        $state = $this->state();
        $seen = (array) ($state['merge_notices'] ?? []);
        $key = preg_replace('/\b[0-9a-f]{7,64}\b/', '<sha>', $line);
        if (in_array($key, $seen, true)) {
            return;
        }
        $this->notice($line);
        $state['merge_notices'] = array_slice([...$seen, $key], -20);
        $this->saveState($state);
    }

    /** Forgets the merge notices that match $pattern: their cause cleared. */
    private function forgetNotices(string $pattern): void
    {
        $state = $this->state();
        if (($kept = self::forget($state, $pattern)) !== $state) {
            $this->saveState($kept);
        }
    }

    /**
     * @param  array<string, mixed>  $state  run.json
     * @return array<string, mixed>
     */
    private static function forget(array $state, string $pattern): array
    {
        $seen = (array) ($state['merge_notices'] ?? []);
        $kept = array_values(preg_grep($pattern, $seen, PREG_GREP_INVERT) ?: []);

        return $kept === $seen ? $state : ['merge_notices' => $kept] + $state;
    }

    /** Why the queue waits, logged once per reason (run.json `merge_waits`). */
    private function mergeWaits(string $why): void
    {
        $state = $this->state();
        if (($state['merge_waits'] ?? null) !== $why) {
            $this->log("merge waits: {$why}");
            $this->saveState(['merge_waits' => $why] + $state);
        }
    }

    /**
     * The cards of this machine in the merge queue.
     *
     * @return list<Card>
     */
    private function queued(Snapshot $snapshot): array
    {
        $queued = array_map(fn (Card $c) => $c->id(), MergeQueue::cards($snapshot));

        return $this->local($snapshot, fn (Card $c) => in_array($c->id(), $queued, true));
    }

    /** A done card whose clone is still here (another machine merged it, or a teardown failed), not tidied yet in this run. */
    private function leftover(Snapshot $snapshot): ?string
    {
        foreach (glob($this->paths()->worktrees().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $head = trim((string) CloneFile::read($dir, '.git/HEAD'));
            if (! str_starts_with($head, 'ref: refs/heads/') || $this->worktrees->isMergeClone($dir)) {
                continue;
            }
            $branch = substr($head, strlen('ref: refs/heads/'));
            foreach ($snapshot->cards(fn (Card $c) => $c->stage() === 'done' && ($c->work()['branch'] ?? null) === $branch) as $card) {
                if (! isset($this->tidied[$card->id()])) {
                    return $card->id();
                }
            }
        }

        return null;
    }

    private function step(): MergeStep
    {
        return $this->step ??= new MergeStep($this->paths(), $this->config(), $this->store(), new Actor('main', $this->session), $this->log(...), $this->log(...));
    }

    private function mergeLease(): MergeLease
    {
        return new MergeLease($this->store(), $this->paths(), new Actor('main', $this->session));
    }

    /** `promote --auto`; what it could not write is a notice once per message (run.json `promote`), not in every pass. */
    private function promote(): void
    {
        $promote = $this->kanban(['promote', '--auto']);
        $failed = $promote->isSuccessful() ? [] : (array_values(array_filter(array_map('trim', explode("\n", $promote->getErrorOutput())))) ?: [self::why($promote)]);
        $state = $this->state();
        foreach (array_diff($failed, (array) ($state['promote'] ?? [])) as $line) {
            $this->notice("promote --auto: {$line}");
        }
        $state['promote'] = $failed;
        $this->saveState($state);
    }

    /**
     * The block for a card whose last two verdicts since its start rejected it on the same criteria, with the
     * run.json key that keeps it and the last verdict's hash, or null. Once per verdict (`looped`): a card unblocked by
     * hand goes on.
     *
     * @return array{string, string, string}|null
     */
    private function rejectLoop(Card $card): ?array
    {
        $since = (string) ($card->work()['started'] ?? '');
        $verdicts = array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'verdict' && (string) ($e['at'] ?? '') >= $since));
        // one verdict applied twice is one reject
        $verdicts = array_values(array_filter($verdicts, fn (array $v, int $i) => $i === 0 || ($v['hash'] ?? null) !== ($verdicts[$i - 1]['hash'] ?? null), ARRAY_FILTER_USE_BOTH));
        [$before, $last] = array_slice([null, null, ...$verdicts], -2);
        $ids = fn (?array $v) => ($v['decision'] ?? null) === 'reject' ? array_map(fn (string $f) => (int) $f, (array) ($v['failed'] ?? [])) : [];
        $state = $this->state();
        if ($ids($last) === [] || $ids($last) !== $ids($before) || ($state['looped'][$card->id()] ?? null) === $last['hash']) {
            return null;
        }
        $failed = $ids($last);

        return [mb_strimwidth('kanban run: rejected 2× on '.(count($failed) === 1 ? 'criterion ' : 'criteria ').implode(', ', $failed).': '
            .preg_replace('/^\d+: /', '', (string) $last['failed'][0]), 0, 500, '…'), 'looped', (string) $last['hash']];
    }

    /**
     * The block for a card the merge sent back MERGER_RUNS times since its start (`merge` entries `result: back`), with
     * the run.json key that keeps it and the last one's id, or null. Once per send-back (`looped_merge`), as rejectLoop.
     *
     * @return array{string, string, string}|null
     */
    private function mergeLoop(Card $card): ?array
    {
        $since = (string) ($card->work()['started'] ?? '');
        $back = array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'merge' && ($e['result'] ?? null) === 'back' && (string) ($e['at'] ?? '') >= $since));
        $last = end($back);
        if (count($back) < self::MERGER_RUNS || ($this->state()['looped_merge'][$card->id()] ?? null) === $last['id']) {
            return null;
        }

        return [mb_strimwidth('kanban run: sent back by the merge '.count($back).'×: '.strtok((string) $last['note'], "\n"), 0, 340, '…'), 'looped_merge', (string) $last['id']];
    }

    /** Parked work a drain finishes: not a card whose last move was a `stop`. */
    private static function inFlight(Card $card): bool
    {
        return PullPolicy::parked($card) && self::lastMove($card) !== 'stop';
    }

    /** What moved the card to its stage last (its last `stage` entry's `via`). */
    private static function lastMove(Card $card): ?string
    {
        $stages = array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'stage'));

        return $stages === [] ? null : ($stages[count($stages) - 1]['via'] ?? null);
    }

    /** The headless session of the card's agent of $type to resume: one started under the claim the card holds now. */
    private function resumable(Runtime $runtime, Card $card, string $type): ?string
    {
        $last = $runtime->agentFor($card->id(), $type);

        return ! empty($last['headless']) && (string) ($last['started_at'] ?? '') >= (string) ($card->claim()['at'] ?? '') ? (string) $last['agent_id'] : null;
    }

    /** Whether a card stack fits under `stack.max_stacks` now, after freeing the slots a start lost; $capped says why not. */
    private function room(): bool
    {
        if ($this->stackFull() !== null) {
            $this->freeLeakedSlots($this->store()->snapshot());
        }

        return ($this->capped = $this->stackFull()) === null;
    }

    /**
     * `start`, then the agent the claim it made is for: a planner in planning, a worker in doing (the card may have moved
     * between this pass's pick and its start). A refused start holds the card for the rest of this run.
     */
    private function begin(Card $card): bool
    {
        $start = $this->kanban(['start', $card->id()]);
        if (! $start->isSuccessful()) {
            $this->log($this->hold($card->id(), $start));

            return false;
        }
        $card = $this->store()->snapshot()->resolve($card->id());
        $type = $card->stage() === 'planning' ? AgentRun::PLANNER : AgentRun::WORKER;
        if (($session = $this->launch($card, $type)) === null) {
            return false;
        }
        $this->log("{$card->id()} ".($type === AgentRun::PLANNER ? 'planning started; planner ' : 'started; worker ').substr($session, 0, 8).' launched '.$this->agents->pins($session));

        return true;
    }

    /**
     * Notices for what changed on the board since the last report: a card blocked without a question (by its agent, the
     * stop gate or this run), a failure filed as already on main, a card the merge sent back or holds, a merge lease
     * another machine stopped beating, more package findings. Each is reported once, kept in run.json.
     *
     * @param  array<string, mixed>  $state
     */
    private function watch(array $state): void
    {
        $state = array_replace($state, $this->state());
        $snapshot = $this->store()->snapshot();
        $seen = (array) ($state['seen'] ?? []);
        $now = [];
        foreach ($snapshot->cards(fn (Card $c) => $c->atWork() && $c->blocked() !== null && ! $c->asks()) as $card) {
            $now[$card->id()] = (string) $card->blocked();
            if (($seen[$card->id()] ?? null) !== $now[$card->id()]) {
                $this->notice("{$card->id()} blocked: {$card->blocked()}");
                // the owner looks at it now: what fails after it is unblocked is raised again
                $state = self::forget($state, '/^'.preg_quote($card->id(), '/').'\b/');
            }
        }
        $state['seen'] = $now;
        // what the agents and the merge queue filed as already on main has no area, so no promote takes it
        $filed = array_map(fn (Card $c) => $c->id(), $snapshot->cards(fn (Card $c) => $c->stage() === 'backlog'
            && in_array(Applier::MAIN_RED, $c->labels(), true) && $c->areas() === []));
        foreach (array_diff($filed, (array) ($state['main_red'] ?? [])) as $id) {
            $this->notice("{$id} ".$snapshot->card($id)?->title().': a failure already on main, in backlog: give it an area and promote it first');
        }
        $state['main_red'] = $filed;
        // the send-back reported last of each card at work, wherever it has been since
        $back = array_intersect_key((array) ($state['merge_back'] ?? []), array_flip(array_map(fn (Card $c) => $c->id(), $snapshot->cards(fn (Card $c) => $c->atWork()))));
        foreach ($this->local($snapshot, fn (Card $c) => $c->stage() === 'doing') as $card) {
            $last = array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'merge' && ($e['result'] ?? null) === 'back'
                && (string) ($e['at'] ?? '') >= (string) ($card->work()['started'] ?? '')));
            if (($entry = end($last)) !== false && ($back[$card->id()] ?? null) !== $entry['id']) {
                $back[$card->id()] = $entry['id'];
                $this->notice("{$card->id()} sent back by the merge: ".mb_strimwidth((string) strtok((string) $entry['note'], "\n"), 0, 300, '…'));
            }
        }
        $state['merge_back'] = $back;
        $held = array_intersect_key(MergeQueue::heldCards($snapshot, $this->step()->originMain()), array_flip(array_map(fn (Card $c) => $c->id(), $this->queued($snapshot))));
        foreach ($held as $id => $why) {
            ($state['merge_held'][$id] ?? null) === $why || $this->notice("{$id} {$why}");
        }
        $state['merge_held'] = $held;
        $lease = $snapshot->mergeLease();
        $stood = $this->queue['stood'] ?? null;
        if ($lease !== null && $stood !== null && $stood >= MergeLease::EXPIRE_SECONDS / 2 && ! $this->mergeLease()->mine($snapshot)
            && ($state['merge_stalled'] ?? null) !== $lease['id'].$lease['beat']) {
            $this->notice('merge lease '.MergeLease::describe($lease).', no beat for '.intdiv((int) $stood, 60).' min');
            $state['merge_stalled'] = $lease['id'].$lease['beat'];
        }
        $pending = count(Findings::pending($snapshot));
        if ($pending > (int) ($state['upstream'] ?? 0)) {
            $this->notice("upstream: {$pending} package findings pending (`kanban upstream`)");
        }
        $state['upstream'] = $pending;
        $this->saveState($state);
    }

    private function draining(): bool
    {
        return $this->option('drain') || is_file($this->paths()->runtime(self::DRAIN));
    }

    /**
     * No agent runs, no merge runs or waits here, no start of this checkout waits to be finished, and no card of this
     * machine is at work (doing, review, held in planning) unblocked: one waiting on the owner, or an approved one the
     * queue holds while main is red or cannot merge at all, is not in flight.
     */
    private function drained(): bool
    {
        $snapshot = $this->store()->snapshot();
        $held = $this->cannotMerge() !== null ? array_map(fn (Card $c) => $c->id(), MergeQueue::cards($snapshot))
            : array_keys(MergeQueue::heldCards($snapshot, $this->step()->originMain()));

        return $this->agents->running() === [] && ! (new MergeRun($this->paths()))->alive() && (new MergeState($this->paths()))->read() === null
            && $this->unstarted($snapshot) === []
            && $this->local($snapshot, fn (Card $c) => $c->atWork() && ! $c->asks() && ! in_array($c->id(), $held, true)) === [];
    }

    /** Launches an evaluator on the card's branch as it is. A clone with uncommitted changes goes back to its worker. */
    private function evaluate(string $id): bool
    {
        // uncommitted changes to tracked files are the worker's; untracked files are what a check left
        if ($this->changed($this->store()->snapshot()->resolve($id))) {
            $back = $this->kanban(['move', $id, 'doing', '--reason=uncommitted changes in its clone: its worker commits them']);
            $back->isSuccessful() ? $this->log("{$id} back to doing: uncommitted changes in its clone") : $this->block($id, $back);

            return $back->isSuccessful();
        }
        if (($session = $this->launch($this->store()->snapshot()->resolve($id), AgentRun::EVALUATOR)) === null) {
            return false;
        }
        $this->log("{$id} evaluator ".substr($session, 0, 8).' launched '.$this->agents->pins($session));

        return true;
    }

    /**
     * An ended run: a usage limit pauses every launch; a run that left its card as it found it is a strike, and the
     * third in a row blocks the card.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function reaped(array $run, array $state): array
    {
        $id = (string) $run['card'];
        $this->log("{$id} ".str_replace('kanban-', '', (string) $run['type']).' '.substr((string) $run['session'], 0, 8).' ended'
            .($run['error'] !== null ? " with {$run['error']}" : '').": {$run['turns']} turns, {$run['tokens']} tokens, $".number_format((float) $run['cost_usd'], 2));
        if ($run['limit']) {
            $state['paused_until'] = time() + self::PAUSE_SECONDS;
            $this->notice('paused until '.date('H:i', $state['paused_until']).': usage limit; then the card resumes');

            return $this->saveState($state);
        }
        if ($this->agents->stopping($id) || $run['type'] === AgentRun::MERGER) {
            // ended by a `stop`, or a merger, whose runs without a result merger() counts: no strike against the card
            return $state;
        }
        $card = $this->store()->snapshot()->card($id);
        $moved = $card === null || $card->stage() !== $run['stage'] || $card->blocked() !== null
            || ($card->work()['approved']['head'] ?? null) !== $run['approved'] || ($card->planned()['at'] ?? null) !== ($run['planned'] ?? null);
        $strikes = $moved ? 0 : (int) ($state['strikes'][$id] ?? 0) + 1;
        if ($strikes >= self::STRIKES) {
            $why = 'kanban run: no progress in '.self::STRIKES.' agent runs (last: '.($run['error'] ?? 'ended without a change').')';
            // a failed `set` keeps the count: the next run that ends blocks the card again
            $strikes = $this->kanban(['set', $id, "blocked={$why}"])->isSuccessful() ? 0 : $strikes;
        }
        $state['strikes'][$id] = $strikes;

        return $this->saveState($state);
    }

    /**
     * Cards of this machine: their clone is here, and no `stop` is taking them down.
     *
     * @param  callable(Card): bool  $filter
     * @return list<Card>
     */
    private function local(Snapshot $snapshot, callable $filter): array
    {
        return array_values(array_filter($snapshot->cards($filter), fn (Card $c) => ($c->blocked() === null || $c->asks())
            && is_dir($this->paths()->main.'/'.($c->work()['worktree'] ?? "\0")) && ! $this->agents->stopping($c->id())));
    }

    /**
     * Cards in doing or held in planning whose start from this checkout was cut short after the claim (another checkout on
     * this machine keeps its own): blocked for want of a stack slot, or with no clone here once a block was cleared. They go
     * before any new start. Not one a `stop` is taking down.
     *
     * @return list<Card>
     */
    private function unstarted(Snapshot $snapshot): array
    {
        return array_values($snapshot->cards(fn (Card $c) => $c->atWork() && $c->stage() !== 'review' && ($c->work()['host'] ?? null) === gethostname()
            && ! $this->agents->stopping($c->id()) && in_array($c->work()['started'] ?? null, StartCommand::marks($this->paths(), $c->id()), true)
            && ($c->blocked() === null ? $this->cutShort($c) : self::slotLost((string) $c->blocked()))));
    }

    /** No clone yet, or a clone whose stack never came up: what `start` run again finishes. */
    private function cutShort(Card $card): bool
    {
        return ! is_dir($this->paths()->main.'/'.($card->work()['worktree'] ?? "\0"))
            || ($this->worktrees->stackEnabled() && ($card->work()['stack'] ?? null) === null);
    }

    private static function slotLost(string $blocked): bool
    {
        return str_starts_with($blocked, 'start failed: '.PortRegistry::NO_SLOT) || str_starts_with($blocked, 'start failed: '.PortRegistry::NO_FREE);
    }

    private function changed(Card $card): bool
    {
        return $this->worktrees->changed($this->paths()->main.'/'.$card->work()['worktree']) !== [];
    }

    /** The card's start took its stack slot before it was cut short: resuming it takes no other. */
    private function holdsSlot(Card $card): bool
    {
        return $this->worktrees->stackEnabled() && $this->worktrees->registry()->find($this->paths()->main.'/'.($card->work()['worktree'] ?? "\0")) !== null;
    }

    /** Why no card stack fits under `stack.max_stacks` now, or null (stacks off included). */
    private function stackFull(): ?string
    {
        return $this->worktrees->stackEnabled() ? $this->worktrees->registry()->full() : null;
    }

    /**
     * Frees this checkout's slots whose clone is gone and whose card is not at work, for more than LEAKED_SECONDS: a start
     * killed before its claim, or a finish whose stack down failed. The stack goes down first. A start cut short after
     * the claim keeps its slot.
     */
    private function freeLeakedSlots(Snapshot $snapshot): void
    {
        foreach ($this->worktrees->registry()->all() as $entry) {
            $card = $snapshot->card((string) ($entry['card'] ?? ''));
            if (($entry['repo'] ?? null) !== $this->paths()->main || is_dir((string) $entry['worktree']) || ($card?->atWork() ?? false)
                || strtotime((string) ($entry['created_at'] ?? '')) > time() - self::LEAKED_SECONDS) {
                continue;
            }
            // its stack may still run (a stack down that failed): down first, as `stack gc` does, or the slot stays
            if (Stack::downProject((string) $entry['project'], ['-v', '--remove-orphans'])['code'] !== 0) {
                continue;
            }
            $this->worktrees->registry()->release((string) $entry['worktree']);
            $this->log("slot {$entry['slot']} freed: {$entry['project']} down, its clone gone and ".($entry['card'] ?? '?').' not at work');
        }
    }

    /**
     * `start` again finishes a start cut short; then its worker (planner) runs. A slot lost again is retried once one is
     * free; any other failure blocks the card, which `watch` reports.
     */
    private function resumeStart(Card $card): bool
    {
        $start = $this->kanban(['start', $card->id()]);
        if (! $start->isSuccessful()) {
            $this->log("{$card->id()} start not resumed: ".self::why($start));
            if (! self::slotLost((string) $this->store()->snapshot()->resolve($card->id())->blocked())) {
                $this->block($card->id(), $start);
            }

            return false;
        }
        $type = $card->stage() === 'planning' ? AgentRun::PLANNER : AgentRun::WORKER;
        if (($session = $this->launch($this->store()->snapshot()->resolve($card->id()), $type)) === null) {
            return false;
        }
        $this->log("{$card->id()} start resumed; ".str_replace('kanban-', '', $type).' '.substr($session, 0, 8).' launched '.$this->agents->pins($session));

        return true;
    }

    /** The session of the agent launched for the card, or null when a `stop` under way refuses it: the pass goes on with the next card. */
    private function launch(Card $card, string $type, ?string $resume = null, ?string $worktree = null): ?string
    {
        try {
            return $this->agents->launch($card, $type, $resume, $worktree);
        } catch (PolicyRefused $e) {
            $this->log("{$card->id()} ".str_replace('kanban-', '', $type).' not launched: '.$e->getMessage());

            return null;
        } catch (LockTimeout $e) {
            throw $e;
        } catch (Throwable $e) {
            // the pass goes on with the other cards
            $this->notice("{$card->id()} ".str_replace('kanban-', '', $type).' not launched: '.$e->getMessage());

            return null;
        }
    }

    /** Skips starting a card whose start was refused for the rest of this run: a full stack pool or a lost claim passes. */
    private function hold(string $id, Process $process): string
    {
        $this->held[$id] = self::why($process);

        return "{$id} held: {$this->held[$id]}";
    }

    /** Blocks the card on the board with the failed command's reason: the orchestrator judges it (`watch` reports it). */
    private function block(string $id, Process $process): void
    {
        $this->kanban(['set', $id, 'blocked=kanban run: '.mb_substr(self::why($process), 0, 400)]);
    }

    private static function why(Process $process): string
    {
        return trim(strtok($process->getErrorOutput() ?: $process->getOutput(), "\n") ?: 'exit '.$process->getExitCode());
    }

    private function notice(string $line): void
    {
        $this->notices[] = $line;
        $this->log($line);
    }

    /** The project's `vendor/bin/kanban …` (a symlinked package's own bin finds no project) as this run's main session; a lease another session took ends the run (exit 6). */
    private function kanban(array $args): Process
    {
        $process = new Process([PHP_BINARY, $this->paths()->main.'/vendor/bin/kanban', ...$args], $this->paths()->main, ['KANBAN_SESSION' => $this->session], null, null);
        $process->run();
        if ($process->getExitCode() === LockTimeout::EXIT) {
            throw new LockTimeout(trim($process->getErrorOutput()));
        }

        return $process;
    }

    /** @return array<string, mixed> pause and strikes, kept across passes and restarts */
    private function state(): array
    {
        $state = json_decode((string) @file_get_contents($this->paths()->runtime('run.json')), true);

        return is_array($state) ? $state : [];
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): array
    {
        $this->paths()->ensureRuntime();
        file_put_contents($this->paths()->runtime('run.json'), json_encode($state));

        return $state;
    }

    /** On the output, and in run.log, where `kanban-status` reads what the run did last. */
    private function log(string $line): void
    {
        $this->say(date('H:i:s').' '.$line);
        $this->paths()->ensureRuntime();
        file_put_contents($this->paths()->runtime('run.log'), date('Y-m-d H:i:s').' '.$line."\n", FILE_APPEND | LOCK_EX);
    }
}
