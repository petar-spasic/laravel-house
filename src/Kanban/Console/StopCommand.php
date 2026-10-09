<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\AgentRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeRun;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeStep;
use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Waiting;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'kanban:stop')]
class StopCommand extends Command
{
    protected $signature = 'kanban:stop
        {id : Card id or unique prefix}
        {--to= : ready, backlog or dropped}
        {--keep-branch : Keep the branch even without commits}
        {--force : Remove a dirty worktree, stop a card another machine is working on, or drop a staged plan not yet applied}
        {--reason= : Why (required for dropped)}';

    protected $description = 'Stop work on a card: its headless agent ended, stack down, slot released, worktree removed; a branch with commits is parked. A planned card in planning goes to ready this way';

    protected function perform(): int
    {
        $this->requireMainOrOwner('stop');
        $to = (string) $this->option('to');
        if (! in_array($to, ['ready', 'backlog', 'dropped'], true)) {
            throw new Invalid('--to must be ready, backlog or dropped');
        }
        $reason = $this->option('reason');
        if ($to === 'dropped' && trim((string) $reason) === '') {
            throw new PolicyRefused('dropping needs a reason (--reason)');
        }
        $worktrees = new Worktrees($this->paths(), $this->config());
        (new Lease($this->paths()))->acquire($this->actor());
        $card = $this->store()->card($this->argument('id'));
        $id = $card->id();
        $planning = $card->stage() === 'planning';
        if (! $card->atWork()) {
            throw new PolicyRefused($planning ? "{$id} is planning and no planner holds it: `kanban move {$id} backlog|dropped`" : "{$id} is {$card->stage()}; only doing or review cards are stopped");
        }
        // ready only once its planner's plan is on the card and covers it: checked before anything comes down
        if ($planning && $to === 'ready' && ($why = Plan::unreleased($card)) !== null) {
            throw new PolicyRefused($why.' (`--to=backlog|dropped` takes the planner off it)');
        }
        $host = $card->host();
        if ($host !== null && $host !== (string) gethostname() && ! $this->option('force')) {
            throw new PolicyRefused("{$id} is being worked on at {$host}, not on this machine: stop it there, or use --force to revert it here anyway");
        }
        $staged = (new Runtime($this->paths()))->staged($id, 'plan');
        if ($staged !== null && ! isset($staged['refused']) && (string) ($staged['staged_at'] ?? '') >= (string) ($card->claim()['at'] ?? '') && ! $this->option('force')) {
            throw new PolicyRefused("{$id}: its planner staged a plan not yet applied: `kanban wait {$id}` applies it when the planner stops (`kanban apply {$id}` once it has), or --force drops it");
        }
        $work = $card->work() ?? [];
        $path = isset($work['worktree']) ? $this->paths()->main.'/'.$work['worktree'] : $this->paths()->worktree($id, $card->title());
        $branch = $work['branch'] ?? null;
        $force = (bool) $this->option('force');

        $exists = is_dir($path);
        // in review the worker left a clean clone: untracked files since are what a check left, removed with it. A planner's
        // clone holds nothing to keep. Checked before its agent and merge are ended, which a refusal then leaves working,
        // and again after
        $review = $card->stage() === 'review';
        $dirty = fn () => $exists && ! $force && ! $planning && ($changed = $review ? $worktrees->changed($path) : $worktrees->dirty($path)) !== []
            ? new PolicyRefused("{$this->paths()->relative($path)} has uncommitted changes; commit them on the branch or use --force", $changed) : null;
        if (($refused = $dirty()) !== null) {
            throw $refused;
        }
        // a merge of the card in flight here: after any refusal, its run ended and its lease given back, never mid-push
        $merging = (new MergeState($this->paths()))->read();
        $mergeLock = null;
        if (($merging['card'] ?? null) === $id) {
            if ($merging['phase'] === MergeState::PUSHING) {
                throw new Waiting("{$id} is being pushed to main: wait for it");
            }
            $run = new MergeRun($this->paths());
            $run->stop();
            $mergeLock = $run->lock();
        }
        // marked from before its agent ends until its stage changes, so no `kanban run` pass resumes or restarts it meanwhile.
        // A failure from here keeps the mark until it expires: the owner stopped the agent, so no pass may bring it back
        $agents = new AgentRun($this->paths(), $this->config());
        $agents->markStopping($id);
        try {
            // a run that outlived its card would keep working on a clone that is gone, and count as live
            foreach ($agents->stopCard($id) as $run) {
                $this->say('stopped its '.str_replace('kanban-', '', (string) $run['type']).' '.substr((string) $run['session'], 0, 8));
            }
            if ($mergeLock !== null) {
                (new MergeStep($this->paths(), $this->config(), $this->store(), $this->actor(), $this->say(...), $this->fault(...)))->abort($id);
            }
            // what the agent's commands wrote until they ended; leftovers are named only beside no tracked change
            $leftovers = $exists && ! $force && $review ? $worktrees->leftovers($path) : null;
            if ($leftovers === null && ($refused = $dirty()) !== null) {
                throw $refused;
            }
            if (! $worktrees->down($path, $work['stack']['project'] ?? null)) {
                throw new StackFailed("docker compose down failed for {$path}; the slot is kept. Retry, or `kanban stack gc` later");
            }
            $this->say("stack down {$this->paths()->relative($path)}");
            if ($exists) {
                $worktrees->remove($path, $force || $planning || $leftovers !== null, is_string($branch) ? $branch : null);
                $this->say("removed worktree {$this->paths()->relative($path)}");
                if ($leftovers !== null) {
                    $this->say($leftovers);
                }
            }
            $worktrees->prune();

            $parked = null;
            if ($planning && is_string($branch) && $branch === ($work['parked_branch'] ?? null)) {
                // a planner commits nothing: the parked work stays as it was when planning began
                $head = $work['head'] ?? null;
                if (is_string($head) && $worktrees->branchExists($branch) && $worktrees->head('refs/heads/'.$branch) !== $head) {
                    $worktrees->git()->run(['branch', '-f', $branch, $head]);
                    $this->say("reset branch {$branch} to ".substr($head, 0, 7).': planning changes no commit');
                }
                $parked = $branch;
                $this->say("parked branch {$branch} kept");
            } elseif ($planning && is_string($branch) && $worktrees->branchExists($branch)) {
                if ($worktrees->deleteBranch($branch, true)) {
                    $this->say("deleted branch {$branch}");
                }
            } elseif (is_string($branch) && $worktrees->branchExists($branch)) {
                $ahead = $worktrees->commitsAhead($branch);
                if ($ahead > 0 || $this->option('keep-branch')) {
                    $parked = $branch;
                    $this->say("parked branch {$branch} ({$ahead} commit(s))");
                } elseif ($worktrees->deleteBranch($branch, true)) {
                    $this->say("deleted branch {$branch}");
                }
            }

            $stopped = $planning && $to === 'ready'
                ? $this->transitions()->planned($id, $this->actor(), $parked)
                : $this->transitions()->stop($id, $to, $this->actor(), $reason, $parked);
        } catch (Throwable $e) {
            $this->fault("{$id} not stopped: its agent is ended, and `kanban run` launches nothing for it for ".intdiv(AgentRun::STOPPING_SECONDS, 60).' min; `stop` it again, or the run resumes it then');
            throw $e;
        }
        $agents->unmarkStopping($id);
        $stages = array_values(array_filter($stopped->log(), fn (array $e) => ($e['event'] ?? null) === 'stage'));
        $why = $to === 'ready' && $stopped->stage() === 'planning' ? (string) (end($stages)['reason'] ?? '') : null;
        $this->say("{$id} {$card->stage()}→{$stopped->stage()}".($why === null ? '' : ": {$why}; a planner plans ".($parked === null ? 'it' : 'the rest')));
        $this->reportPending();

        return self::SUCCESS;
    }
}
