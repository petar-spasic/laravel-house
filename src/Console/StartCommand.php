<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Code\Worktrees;
use PetarSpasic\Kanban\Protocol\Lease;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Support\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'kanban:start')]
class StartCommand extends Command
{
    protected $signature = 'kanban:start
        {id : Card id or unique prefix}
        {--force : Skip the capacity and policy checks (main session only; logged)}';

    protected $description = 'Claim a ready card, create its worktree, .env and port slot, and start its Docker stack';

    protected function perform(): int
    {
        $this->requireMainOrOwner('start');
        $worktrees = new Worktrees($this->paths(), $this->config());
        $worktrees->requireMain();
        (new Lease($this->paths()))->acquire($this->actor());

        $card = $this->store()->card($this->argument('id'));
        $id = $card->id();
        $path = $this->paths()->worktree($id, $card->title());
        if (file_exists($path)) {
            throw new PolicyRefused("{$this->paths()->relative($path)} already exists; remove it or run `kanban stack gc` first");
        }
        $parked = $card->work()['parked_branch'] ?? null;
        $branch = is_string($parked) && $worktrees->branchExists($parked) ? $parked : $worktrees->branchFor($id, $card->title());
        $attempt = 1 + count(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'stage' && ($e['to'] ?? null) === 'doing' && ($e['via'] ?? null) === 'start'));

        $this->transitions()->start($id, $this->actor(), force: (bool) $this->option('force'));

        $work = [
            'branch' => $branch, 'base' => null, 'worktree' => $this->paths()->relative($path), 'host' => gethostname() ?: null,
            'stack' => null, 'attempt' => $attempt, 'head' => null, 'approved' => null, 'merge' => null,
            'started' => Clock::now(), 'finished' => null,
        ];
        try {
            $work['base'] = $worktrees->head('refs/heads/'.$worktrees->mainBranch());
            $worktrees->add($path, $branch);
            $worktrees->copyDependencies($path);
            if ($worktrees->prepare($path, $branch, $id) !== null) {
                $entry = $worktrees->up($path, $branch, $id);
                $work['stack'] = ['project' => $entry['project'], 'slot' => $entry['slot'], 'ports' => $entry['ports'], 'url' => $entry['url']];
            }
        } catch (Throwable $e) {
            $reason = 'start failed: '.$e->getMessage();
            $this->store()->update($id, function (array $data) use ($work, $reason) {
                $data['work'] = $work;
                $data['blocked'] = mb_substr($reason, 0, 500);

                return $data;
            }, $this->actor());
            $this->fault("{$id} stays in doing, blocked: {$reason}");
            $this->fault("clean up with `kanban stop {$id} --to=ready --force`");
            throw $e;
        }

        $this->store()->update($id, function (array $data) use ($work) {
            $data['work'] = $work;

            return $data;
        }, $this->actor());

        $this->say("started {$id}");
        $this->say("worktree {$path}");
        $this->say("branch {$branch}".($branch === $parked ? ' (parked branch reused)' : ''));
        if ($work['stack'] !== null) {
            $stack = $work['stack'];
            $this->say("stack {$stack['project']} slot {$stack['slot']}".($stack['url'] !== null ? " {$stack['url']}" : ''));
            $this->say('ports '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($stack['ports']), $stack['ports'])));
            $this->say('starting: the worker runs `vendor/bin/kanban stack wait` before using it');
        } else {
            $this->say('stack none (stack.compose_file unset or missing)');
        }
        $this->say("Agent(subagent_type=\"kanban-worker\", description=\"{$id} ".Worktrees::label($card->title())."\", isolation=\"worktree\", prompt=\"Card {$id}. Worktree {$path}\")");
        $this->reportPending();

        return self::SUCCESS;
    }
}
