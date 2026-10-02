<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'kanban:finish')]
class FinishCommand extends Command
{
    protected $signature = 'kanban:finish {id : Card id or unique prefix}';

    protected $description = 'Merge an approved card into main, mark it done, then tear down its stack, worktree and branch';

    protected function perform(): int
    {
        $this->requireMainOrOwner('finish');
        $worktrees = new Worktrees($this->paths(), $this->config());
        $worktrees->requireMain();
        (new Lease($this->paths()))->acquire($this->actor());
        $snapshot = $this->store()->snapshot();
        $card = $snapshot->resolve($this->argument('id'));
        $id = $card->id();
        $work = $card->work() ?? [];
        $main = $worktrees->mainBranch();

        if ($card->stage() !== 'review') {
            throw new PolicyRefused("{$id} is {$card->stage()}, not review");
        }
        $branch = (string) ($work['branch'] ?? '');
        if ($branch === '' || ! $worktrees->branchExists($branch)) {
            throw new PolicyRefused("{$id}: branch '{$branch}' does not exist");
        }
        $head = $worktrees->head('refs/heads/'.$branch);
        $approved = $work['approved'] ?? null;
        if (! is_array($approved) || ($approved['head'] ?? null) !== $head) {
            throw new PolicyRefused("{$id}: no approval for the branch head ".substr($head, 0, 7).(is_array($approved) ? ' (approved '.substr((string) ($approved['head'] ?? '?'), 0, 7).')' : '').'; run the evaluator again');
        }
        $path = $this->paths()->main.'/'.($work['worktree'] ?? '');
        if (isset($work['worktree']) && is_dir($path) && ($dirty = $worktrees->dirty($path)) !== []) {
            throw new PolicyRefused("{$id}: the worktree has uncommitted changes", $dirty);
        }
        if (($agent = $this->agent($id, $snapshot)) !== null && $agent !== 'stopped' && ! str_starts_with($agent, 'stale')) {
            throw new PolicyRefused("{$id}: an agent is still bound to the card ({$agent}); wait for it to stop");
        }

        $check = new MergeCheck($worktrees->git(), $main);
        $base = (string) ($approved['base'] ?? $work['base'] ?? '');
        if ($base !== '' && ($overlap = $check->movedOverlap($base, $branch)) !== []) {
            throw new Conflict("{$id}: {$main} moved since approval and changed files the branch changes; `kanban refresh {$id}` and re-verify", $overlap);
        }
        if (($conflicts = $check->conflicts($branch)) !== null) {
            $this->transitions()->sendBack($id, 'refresh', $this->actor(), 'merge into '.$main.' conflicts in '.implode(', ', $conflicts));
            throw new Conflict("{$id}: the branch does not merge cleanly into {$main}; {$id} → doing, `kanban refresh {$id}` hands the conflict to the worker", $conflicts);
        }
        $files = $check->branchFiles($branch);
        if (($uncommitted = $check->uncommittedOverlap($files)) !== []) {
            throw new PolicyRefused("{$id}: the main checkout has uncommitted changes to files the branch changes; commit or stash them first", $uncommitted);
        }

        $git = $worktrees->git();
        $merge = $git->attempt(['merge', '--no-ff', '-m', "{$id}: {$card->title()}", 'refs/heads/'.$branch]);
        if (! $merge->ok()) {
            $git->attempt(['merge', '--abort']);
            throw new Conflict("{$id}: git merge failed and was aborted: ".Worktrees::tail($merge->err ?: $merge->out));
        }
        $sha = $worktrees->head('HEAD');
        $this->say("merged {$id} into {$main} ".substr($sha, 0, 7));
        $this->transitions()->finish($id, $sha, $this->actor());
        $this->say("{$id} review→done");

        $exit = $this->afterMerge($worktrees);
        $compose = $this->setting('stack.compose_file') ?? 'docker-compose.yml';
        if (($rebuild = MergeCheck::rebuildFiles($files, $compose)) !== []) {
            $this->say('rebuild main: '.implode(', ', $rebuild)." changed; run `docker compose -f {$compose} up -d --build --force-recreate`");
        }

        if ($worktrees->down($path, $work['stack']['project'] ?? null)) {
            $this->say(isset($work['stack']['project']) ? "stack down {$work['stack']['project']}; slot released" : 'stack none');
        } else {
            $this->fault("stack down failed for {$path}; the slot is kept until `kanban stack gc`");
            $exit = 7;
        }
        if (isset($work['worktree']) && is_dir($path)) {
            $removed = $git->attempt(['worktree', 'remove', $path]);
            if ($removed->ok()) {
                $this->say("removed worktree {$work['worktree']}");
            } else {
                $this->fault("git worktree remove {$work['worktree']}: ".trim($removed->err));
                $exit = max($exit, 1);
            }
        }
        if ($worktrees->deleteBranch($branch)) {
            $this->say("deleted branch {$branch}");
        } else {
            $this->fault("branch {$branch} kept (git branch -d refused)");
        }
        $worktrees->prune();
        $this->reportPending();

        return $exit;
    }

    /** `finish.after` on main (migrate + ReferenceDataSeeder by default) for projects with a Docker stack. */
    private function afterMerge(Worktrees $worktrees): int
    {
        if (! $worktrees->stackEnabled()) {
            return self::SUCCESS;
        }
        $exit = self::SUCCESS;
        foreach ((array) $this->setting('finish.after', []) as $command) {
            $command = (string) $command;
            if (preg_match('/--class=(\S+)/', $command, $m) && ! is_file($this->paths()->main.'/database/seeders/'.class_basename(str_replace('\\\\', '\\', $m[1])).'.php')) {
                continue;
            }
            $process = Process::fromShellCommandline($command, $this->paths()->main, null, null, 600);
            $process->run();
            if ($process->isSuccessful()) {
                $this->say("after: {$command} ok");
            } else {
                $this->fault("after: {$command} failed (exit {$process->getExitCode()}): ".Worktrees::tail($process->getErrorOutput() ?: $process->getOutput()));
                $exit = 1;
            }
        }

        return $exit;
    }
}
