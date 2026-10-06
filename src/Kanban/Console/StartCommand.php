<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Dependencies;
use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\StackUser;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'kanban:start')]
class StartCommand extends Command
{
    protected $signature = 'kanban:start
        {id : Card id or unique prefix}
        {--force : Skip the capacity and policy checks (main session only; logged)}';

    protected $description = 'Claim a ready card, create its worktree, .env and port slot, and start its Docker stack; run again, it finishes a start cut short';

    /** Runtime directory of the cards this checkout claimed: `kanban run` finishes only these starts. */
    public const STARTED = 'starts';

    protected function perform(): int
    {
        $this->requireMainOrOwner('start');
        $worktrees = new Worktrees($this->paths(), $this->config());
        $worktrees->requireMain();
        (new Lease($this->paths()))->acquire($this->actor());

        $card = $this->store()->card($this->argument('id'));
        $id = $card->id();
        $resumed = $this->resumable($card, $worktrees);
        if ($resumed) {
            $work = $card->work();
            $path = $this->paths()->main.'/'.$work['worktree'];
            $branch = (string) $work['branch'];
            $parked = null;
            if (! $worktrees->isWorktree($path) && file_exists($path)) {
                throw new PolicyRefused("{$work['worktree']} exists but is not a worktree; remove it, then run `kanban start {$id}` again");
            }
        } else {
            $path = $this->paths()->worktree($id, $card->title());
            if (file_exists($path)) {
                throw new PolicyRefused("{$this->paths()->relative($path)} already exists; remove it or run `kanban stack gc` first");
            }
            $parked = $card->work()['parked_branch'] ?? null;
            $branch = is_string($parked) && $worktrees->branchExists($parked) ? $parked : $worktrees->branchFor($id, $card->title());
            $attempt = 1 + count(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'stage' && ($e['to'] ?? null) === 'doing' && ($e['via'] ?? null) === 'start'));

            if ($worktrees->stackEnabled() && ($wrong = (new StackUser($this->paths()->main, (string) $this->setting('stack.compose_file')))->problem()) !== null) {
                throw new PolicyRefused("refused {$id}: {$wrong}");
            }
            // the slot is taken before the claim, so a start that claims the card has its slot
            $reserved = false;
            if ($worktrees->stackEnabled() && $card->stage() === 'ready' && $card->claim() === null && $worktrees->registry()->find($path) === null) {
                try {
                    $worktrees->registry()->allocate($path, ['project' => $worktrees->env()->project($path), 'repo' => $this->paths()->main, 'branch' => $branch, 'card' => $id]);
                    $reserved = true;
                } catch (StackFailed $e) {
                    throw new PolicyRefused("refused {$id}: {$e->getMessage()}");
                }
            }
            // where the work goes is on the card from the claim on, so a start cut short is resumed by running it again
            $work = [
                'branch' => $branch, 'base' => $worktrees->head('refs/heads/'.$worktrees->mainBranch()), 'worktree' => $this->paths()->relative($path),
                'host' => gethostname() ?: null, 'stack' => null, 'attempt' => $attempt, 'head' => null, 'approved' => null, 'merge' => null,
                'started' => Clock::now(), 'finished' => null,
            ];
            try {
                $this->transitions()->start($id, $this->actor(), work: $work, force: (bool) $this->option('force'));
            } catch (Throwable $e) {
                $reserved && $worktrees->registry()->release($path);
                throw $e;
            }
            touch($this->paths()->ensureRuntime(self::STARTED).'/'.$id);
        }

        $merged = [];
        try {
            if (! $worktrees->isWorktree($path)) {
                $worktrees->prune();
                $worktrees->add($path, $branch);
                if ($branch === $parked) {
                    $merged = $this->mergeMain($worktrees, $path, $id);
                }
            }
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
            $this->fault("run `kanban start {$id}` again once that is fixed, or clean up with `kanban stop {$id} --to=ready --force`");
            throw $e;
        }

        $this->store()->update($id, function (array $data) use ($work) {
            $data['work'] = $work;
            if (str_starts_with((string) ($data['blocked'] ?? ''), 'start failed:')) {
                $data['blocked'] = null;
            }

            return $data;
        }, $this->actor());

        $this->say(($resumed ? 'resumed' : 'started')." {$id}");
        $this->say("worktree {$path}");
        $this->say("branch {$branch}".($branch === $parked ? ' (parked branch reused)' : ''));
        foreach ($merged as $line) {
            $this->say($line);
        }
        if ($work['stack'] !== null) {
            $stack = $work['stack'];
            $this->say("stack {$stack['project']} slot {$stack['slot']}".($stack['url'] !== null ? " {$stack['url']}" : ''));
            $this->say('ports '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($stack['ports']), $stack['ports'])));
            $this->say('starting: the worker runs `vendor/bin/kanban stack wait` before using it');
        } else {
            $this->say('stack none (stack.compose_file unset or missing)');
        }
        foreach (Dependencies::problems($this->paths()->main, (array) ($this->setting('worktrees.copy') ?? [])) as $problem) {
            $this->say("warning: {$problem}");
        }
        $this->say(Worktrees::spawnLine($card, 'kanban-worker', $path));
        $this->reportPending();

        return self::SUCCESS;
    }

    /**
     * Merges main into a parked branch before its stack starts, so the branch carries main's board format and the
     * container installs from the merged lockfiles. A conflict is left in progress for the worker, as refresh leaves one.
     *
     * @return list<string> what to print
     */
    private function mergeMain(Worktrees $worktrees, string $path, string $id): array
    {
        $main = $worktrees->mainBranch();
        $git = $worktrees->git($path);
        $before = $worktrees->head('HEAD', $path);
        $merge = $git->attempt(['merge', '--no-edit', $main]);
        $conflicted = array_values(array_filter(explode("\n", trim($git->attempt(['diff', '--name-only', '--diff-filter=U'])->out))));
        if ($conflicted !== []) {
            return [
                "conflict {$id}: merge of {$main} left in progress in {$path}",
                ...array_map(fn (string $file) => "conflicted {$file}", $conflicted),
                'the worker concludes the merge first: resolve each conflict by keeping both sides\' content, `git add` the files and '
                    .'`git commit --no-edit`; until then the stack serves the conflicted tree, and `vendor/bin/kanban stack wait` reloads it after',
            ];
        }
        if (! $merge->ok()) {
            throw new PolicyRefused("git merge {$main} into the parked branch failed: ".Worktrees::tail($merge->err ?: $merge->out));
        }
        $after = $worktrees->head('HEAD', $path);

        return $after === $before ? [] : ["merged {$main} into the parked branch (".substr($before, 0, 7).'..'.substr($after, 0, 7).')'];
    }

    /**
     * A card in doing on this machine whose start was cut short (killed, or failed and fixed since): its worktree or
     * stack is missing, or a failed start blocks it.
     */
    private function resumable(Card $card, Worktrees $worktrees): bool
    {
        $work = $card->work() ?? [];
        if ($card->stage() !== 'doing' || ! is_string($work['worktree'] ?? null) || ! is_string($work['branch'] ?? null) || ($work['host'] ?? null) !== gethostname()) {
            return false;
        }

        return str_starts_with((string) $card->blocked(), 'start failed:') || ! $worktrees->isWorktree($this->paths()->main.'/'.$work['worktree'])
            || ($worktrees->stackEnabled() && ($work['stack'] ?? null) === null);
    }
}
