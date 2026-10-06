<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\AgentRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MainCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Upstream\Findings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The board's driver: the run loop in code. Each pass reaps ended agents, finishes approved cards, starts evaluators and
 * workers headless (AgentRun), parks question cards in backlog and fills the free slots; every board change is a
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

    protected $description = 'Drive the board until stopped: start cards, run their agents headless, evaluate, merge, park questions';

    private const PASS_SECONDS = 15;

    private const PAUSE_SECONDS = 900;

    private const STRIKES = 3;

    /** The runtime file `kanban drain` leaves: every run drains until one has. */
    public const DRAIN = 'run.drain';

    private const PID = 'run.pid';

    private const LOCK = 'run.lock';

    private const LEAKED_SECONDS = 600;

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
        $state = $this->state();
        foreach ($this->agents->ended() as $run) {
            $state = $this->reaped($run, $state);
        }
        $this->kanban(['apply', '--all']);
        $paused = ($state['paused_until'] ?? 0) > time();
        $running = array_column($this->agents->running(), 'card');
        $acted = false;

        $snapshot = $this->store()->snapshot();
        // an approved card that asks the owner (a change to files that steer the agents) waits for the answer
        $approved = $this->local($snapshot, fn (Card $c) => $c->stage() === 'review' && is_string($c->work()['approved']['at'] ?? null) && ! $c->asks());
        usort($approved, fn (Card $a, Card $b) => strcmp($a->work()['approved']['at'], $b->work()['approved']['at']));
        foreach ($approved as $card) {
            if (! in_array($card->id(), $running, true)) {
                $acted = $this->finish($card, $paused) || $acted;
                break;
            }
        }

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
            } elseif (! $paused) {
                $last = $runtime->agentFor($card->id(), AgentRun::WORKER);
                $resume = ! empty($last['headless']) ? (string) $last['agent_id'] : null;
                // a fix that unblocked the card may have landed on main; 5: the worker concludes the conflict first. Not into
                // uncommitted edits (a session that ended mid-work): the worker commits them first (its stop gate), and the
                // next round merges. Untracked files stay out of a merge, and the stop gate catches one folded into it
                $dirty = $this->changed($card);
                if ($resume !== null && ! $dirty && ! in_array(($refresh = $this->kanban(['refresh', $card->id()]))->getExitCode(), [0, 5], true)) {
                    $this->block($card->id(), $refresh);

                    continue;
                }
                $session = $this->agents->launch($card, AgentRun::WORKER, $resume);
                $this->log("{$card->id()} worker ".substr($session, 0, 8).($resume === null ? ' launched' : ' resumed').($resume !== null && $dirty ? ', main not merged: uncommitted changes in its clone' : ''));
                $acted = true;
            }
        }

        if (! $paused) {
            // a drain still starts parked work (a card back from its question, its branch kept): it is in flight
            $draining = $this->draining();
            $draining || $this->kanban(['promote', '--auto']);
            $this->capped = null;
            foreach ((new PullPolicy)->next($this->store()->snapshot(), 99)['cards'] as $card) {
                if ($this->stackFull() !== null) {
                    $this->freeLeakedSlots($this->store()->snapshot());
                }
                if (($this->capped = $this->stackFull()) !== null) {
                    break;
                }
                if (isset($this->held[$card->id()]) || ($draining && ! PullPolicy::parked($card))) {
                    continue;
                }
                $start = $this->kanban(['start', $card->id()]);
                if (! $start->isSuccessful()) {
                    $this->log($this->hold($card->id(), $start));

                    continue;
                }
                $session = $this->agents->launch($this->store()->snapshot()->resolve($card->id()), AgentRun::WORKER);
                $this->log("{$card->id()} started; worker ".substr($session, 0, 8).' launched');
                $acted = true;
            }
        }

        if ($acted || $this->agents->running() !== []) {
            $this->published = false;
        } elseif (! $this->published) {
            $this->published = true;
            $this->log('idle: '.strtok(trim($this->kanban(['publish'])->getOutput()) ?: 'published', "\n"));
            $this->draining() || $this->notice('idle: '.($this->capped !== null ? "no card can start: {$this->capped}" : 'no agent runs and no card can start: plan or promote cards'));
        }
        $this->watch($state);
    }

    /**
     * Notices for what changed on the board since the last report: a card blocked without a question (by its agent, the
     * stop gate or this run), a red main, more package findings. Each is reported once, kept in run.json.
     *
     * @param  array<string, mixed>  $state
     */
    private function watch(array $state): void
    {
        $state = array_replace($state, $this->state());
        $snapshot = $this->store()->snapshot();
        $seen = (array) ($state['seen'] ?? []);
        $now = [];
        foreach ($snapshot->cards(fn (Card $c) => in_array($c->stage(), ['doing', 'review'], true) && $c->blocked() !== null && ! $c->asks()) as $card) {
            $now[$card->id()] = (string) $card->blocked();
            if (($seen[$card->id()] ?? null) !== $now[$card->id()]) {
                $this->notice("{$card->id()} blocked: {$card->blocked()}");
            }
        }
        $state['seen'] = $now;
        $red = (new MainCheck($this->paths()))->red();
        if ($red !== null && ($state['red'] ?? null) !== $red['sha']) {
            $this->notice('main red since '.substr((string) $red['sha'], 0, 7).": `{$red['command']}` fails (".($red['card'] ?? 'no card').')');
        }
        $state['red'] = $red['sha'] ?? null;
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

    /** No agent runs, and no card of this machine is in doing or review unblocked: one waiting on the owner is not in flight. */
    private function drained(): bool
    {
        return $this->agents->running() === []
            && $this->local($this->store()->snapshot(), fn (Card $c) => in_array($c->stage(), ['doing', 'review'], true) && ! $c->asks()) === [];
    }

    /** Merges an approved card; `main moved` sends it back to an evaluator. */
    private function finish(Card $card, bool $paused): bool
    {
        $finish = $this->kanban(['finish', $card->id(), '--ask']);
        if ($finish->isSuccessful()) {
            foreach (explode("\n", trim($finish->getOutput())) as $n => $line) {
                // the merge line, what rebuilding main's stack did and the warnings; a failed rebuild leaves main to act on
                if (str_starts_with($line, 'warning: rebuild main')) {
                    $this->notice("{$card->id()} finish {$line}");
                } elseif ($n === 0 || str_starts_with($line, 'rebuil') || str_starts_with($line, 'warning:')) {
                    $this->log($line);
                }
            }

            return true;
        }
        if ($finish->getExitCode() === 5 && str_contains($finish->getErrorOutput(), 'moved since approval') && ! $paused) {
            return $this->evaluate($card->id());
        }
        if (($asks = $this->store()->snapshot()->resolve($card->id()))->asks()) {
            $this->notice("{$card->id()} waits on the owner: {$asks->blocked()}");

            return true;
        }
        $this->block($card->id(), $finish);

        return false;
    }

    /** Merges main into the card's branch, then launches an evaluator on it. A clone with uncommitted changes goes back to its worker. */
    private function evaluate(string $id): bool
    {
        // uncommitted changes to tracked files are the worker's; untracked files are what a check left, which a merge leaves alone
        if ($this->changed($this->store()->snapshot()->resolve($id))) {
            $back = $this->kanban(['move', $id, 'doing', '--reason=uncommitted changes in its clone: its worker commits them']);
            $back->isSuccessful() ? $this->log("{$id} back to doing: uncommitted changes in its clone") : $this->block($id, $back);

            return $back->isSuccessful();
        }
        $refresh = $this->kanban(['refresh', $id]);
        if (! $refresh->isSuccessful()) {
            // 5: a conflict sent the card back to doing, where its worker resumes; an agent of it still running settles on
            // its own. Anything else (a clone off its branch, say) is the orchestrator's: blocked, so watch() reports it
            if ($refresh->getExitCode() !== 5 && ! str_contains($refresh->getErrorOutput(), 'is still running')) {
                $this->block($id, $refresh);
            }

            return $refresh->getExitCode() === 5;
        }
        $session = $this->agents->launch($this->store()->snapshot()->resolve($id), AgentRun::EVALUATOR);
        $this->log("{$id} evaluator ".substr($session, 0, 8).' launched');

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
        $card = $this->store()->snapshot()->card($id);
        $moved = $card === null || $card->stage() !== $run['stage'] || $card->blocked() !== null
            || ($card->work()['approved']['head'] ?? null) !== $run['approved'];
        $strikes = $moved ? 0 : (int) ($state['strikes'][$id] ?? 0) + 1;
        if ($strikes >= self::STRIKES) {
            $why = 'kanban run: no progress in '.self::STRIKES.' agent runs (last: '.($run['error'] ?? 'ended without a change').')';
            $this->kanban(['set', $id, "blocked={$why}"]);
            $strikes = 0;
        }
        $state['strikes'][$id] = $strikes;

        return $this->saveState($state);
    }

    /**
     * Cards of this machine: their clone is here.
     *
     * @param  callable(Card): bool  $filter
     * @return list<Card>
     */
    private function local(Snapshot $snapshot, callable $filter): array
    {
        return array_values(array_filter($snapshot->cards($filter), fn (Card $c) => ($c->blocked() === null || $c->asks())
            && is_dir($this->paths()->main.'/'.($c->work()['worktree'] ?? "\0"))));
    }

    /**
     * Cards in doing whose start from this checkout was cut short after the claim (another checkout on this machine keeps
     * its own): blocked for want of a stack slot, or with no clone here once a block was cleared. They go before any new
     * start.
     *
     * @return list<Card>
     */
    private function unstarted(Snapshot $snapshot): array
    {
        return array_values($snapshot->cards(fn (Card $c) => $c->stage() === 'doing' && ($c->work()['host'] ?? null) === gethostname()
            && @file_get_contents($this->paths()->runtime(StartCommand::STARTED.'/'.$c->id())) === ($c->work()['started'] ?? null)
            && ($c->blocked() === null ? ! is_dir($this->paths()->main.'/'.($c->work()['worktree'] ?? "\0")) : self::slotLost((string) $c->blocked()))));
    }

    private static function slotLost(string $blocked): bool
    {
        return str_starts_with($blocked, 'start failed: no stack slot') || str_starts_with($blocked, 'start failed: no free slot');
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
     * Frees this checkout's slots that a start took and lost before its claim (it was killed): no clone, and its card not
     * at work here, for more than LEAKED_SECONDS. A start cut short after the claim keeps its slot.
     */
    private function freeLeakedSlots(Snapshot $snapshot): void
    {
        foreach ($this->worktrees->registry()->all() as $entry) {
            $card = $snapshot->card((string) ($entry['card'] ?? ''));
            if (($entry['repo'] ?? null) !== $this->paths()->main || is_dir((string) $entry['worktree'])
                || ($card !== null && in_array($card->stage(), ['doing', 'review'], true))
                || strtotime((string) ($entry['created_at'] ?? '')) > time() - self::LEAKED_SECONDS) {
                continue;
            }
            $this->worktrees->registry()->release((string) $entry['worktree']);
            $this->log("slot {$entry['slot']} freed: a start of ".($entry['card'] ?? '?').' took it and ended before its claim');
        }
    }

    /**
     * `start` again finishes a start cut short; then its worker runs. A slot lost again is retried once one is free; any
     * other failure blocks the card, which `watch` reports.
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
        $session = $this->agents->launch($this->store()->snapshot()->resolve($card->id()), AgentRun::WORKER);
        $this->log("{$card->id()} start resumed; worker ".substr($session, 0, 8).' launched');

        return true;
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
