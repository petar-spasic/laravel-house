<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;
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

    protected $description = 'Stop work on a card: stack down, slot released, worktree removed; a branch with commits is parked';

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
        if (! Stage::isActive($card->stage())) {
            throw new PolicyRefused("{$id} is {$card->stage()}; only doing or review cards are stopped");
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
        if ($exists && ! $force && ($dirty = $worktrees->dirty($path)) !== []) {
            throw new PolicyRefused("{$this->paths()->relative($path)} has uncommitted changes; commit them on the branch or use --force", $dirty);
        }
        if (! $worktrees->down($path, $work['stack']['project'] ?? null)) {
            throw new StackFailed("docker compose down failed for {$path}; the slot is kept. Retry, or `kanban stack gc` later");
        }
        $this->say("stack down {$this->paths()->relative($path)}");
        if ($exists) {
            $worktrees->remove($path, $force, is_string($branch) ? $branch : null);
            $this->say("removed worktree {$this->paths()->relative($path)}");
        }
        $worktrees->prune();

        $parked = null;
        if (is_string($branch) && $worktrees->branchExists($branch)) {
            $ahead = $worktrees->commitsAhead($branch);
            if ($ahead > 0 || $this->option('keep-branch')) {
                $parked = $branch;
                $this->say("parked branch {$branch} ({$ahead} commit(s))");
            } elseif ($worktrees->deleteBranch($branch, true)) {
                $this->say("deleted branch {$branch}");
            }
        }

        $stopped = $this->transitions()->stop($id, $to, $this->actor(), $reason, $parked);
        $this->say("{$id} {$card->stage()}→{$stopped->stage()}");
        $this->reportPending();

        return self::SUCCESS;
    }
}
