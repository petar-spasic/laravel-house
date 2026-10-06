<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:stop')]
class StopCommand extends Command
{
    protected $signature = 'kanban:stop
        {id : Card id or unique prefix}
        {--to= : ready, backlog or dropped}
        {--keep-branch : Keep the branch even without commits}
        {--force : Remove a dirty worktree, or stop a card another machine is working on}
        {--reason= : Why (required for dropped)}';

    protected $description = 'Stop work on a card: stack down, slot released, worktree removed; a branch with commits is parked. A planned card in planning goes to ready this way';

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
        if ($planning && $to === 'ready' && ! Plan::madeUnderClaim($card)) {
            throw new PolicyRefused("{$id} has no plan from its planner yet: it moves to ready once its planner's plan is applied (`--to=backlog|dropped` takes the planner off it)");
        }
        if ($planning && $to === 'ready' && ! Plan::current($card)) {
            throw new PolicyRefused("{$id}'s plan no longer covers it (its criteria or body changed since): its planner revises it");
        }
        $host = $card->host();
        if ($host !== null && $host !== (string) gethostname() && ! $this->option('force')) {
            throw new PolicyRefused("{$id} is being worked on at {$host}, not on this machine: stop it there, or use --force to revert it here anyway");
        }
        $work = $card->work() ?? [];
        $path = isset($work['worktree']) ? $this->paths()->main.'/'.$work['worktree'] : $this->paths()->worktree($id, $card->title());
        $branch = $work['branch'] ?? null;
        $force = (bool) $this->option('force');

        $exists = is_dir($path);
        // in review the worker left a clean clone: untracked files since are what a check left, removed with it. A planner's
        // clone holds nothing to keep
        $review = $card->stage() === 'review';
        if ($exists && ! $force && ! $planning && ($dirty = $review ? $worktrees->changed($path) : $worktrees->dirty($path)) !== []) {
            throw new PolicyRefused("{$this->paths()->relative($path)} has uncommitted changes; commit them on the branch or use --force", $dirty);
        }
        $leftovers = $exists && ! $force && $review ? $worktrees->leftovers($path) : null;
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
        $this->say("{$id} {$card->stage()}→{$stopped->stage()}");
        $this->reportPending();

        return self::SUCCESS;
    }
}
