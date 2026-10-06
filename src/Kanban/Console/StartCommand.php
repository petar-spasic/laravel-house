<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Dependencies;
use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\StackUser;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'kanban:start')]
class StartCommand extends Command
{
    protected $signature = 'kanban:start
        {id : Card id or unique prefix}
        {--force : Skip the capacity and policy checks (main session only; logged)}';

    protected $description = 'Claim a ready card for its worker, or a planning card for its planner: its worktree, .env and port slot, and its Docker stack; run again, it finishes a start cut short';

    /** Runtime directory of this checkout's starts: a file per card listing their `work.started`, one a line. */
    public const STARTED = 'starts';

    protected function perform(): int
    {
        $this->requireMainOrOwner('start');
        $worktrees = new Worktrees($this->paths(), $this->config());
        $worktrees->requireMain();
        (new Lease($this->paths()))->acquire($this->actor());

        $card = $this->store()->card($this->argument('id'));
        $id = $card->id();
        $planning = $card->stage() === 'planning';
        $resumed = $this->resumable($card, $worktrees);
        $elsewhere = null;
        if (! $resumed) {
            $path = $this->paths()->worktree($id, $card->title());
            if (file_exists($path)) {
                throw new PolicyRefused("{$this->paths()->relative($path)} already exists; remove it or run `kanban stack gc` first");
            }
            $parked = $card->work()['parked_branch'] ?? null;
            // a parked branch lives where its worker ran: on another machine the card starts from main
            $elsewhere = is_string($parked) && ! $worktrees->branchExists($parked) ? $parked : null;
            $parked = $elsewhere === null ? $parked : null;
            $branch = is_string($parked) ? $parked : $worktrees->branchFor($id, $card->title());
            $attempt = 1 + count(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'stage' && ($e['to'] ?? null) === 'doing' && ($e['via'] ?? null) === 'start'));

            if ($worktrees->stackEnabled() && ($wrong = (new StackUser($this->paths()->main, (string) $this->setting('stack.compose_file')))->problem()) !== null) {
                throw new PolicyRefused("refused {$id}: {$wrong}");
            }
            // where the work goes is on the card from the claim on, so a start cut short is resumed by running it again
            $work = [
                'branch' => $branch, 'base' => $worktrees->head('refs/heads/'.$worktrees->mainBranch()), 'worktree' => $this->paths()->relative($path),
                'host' => gethostname() ?: null, 'stack' => null, 'attempt' => $attempt, 'head' => null, 'approved' => null, 'merge' => null,
                'started' => Clock::now(), 'finished' => null,
            ];
            // a planner's clone of a parked branch keeps it parked and records its head: planning changes no commit, and
            // `stop` holds the branch to that. A planner of a card parked elsewhere plans it from main, the branch forgotten
            if ($planning && $parked !== null) {
                $work = ['head' => $worktrees->head('refs/heads/'.$branch), 'parked_branch' => $parked] + $work;
            } elseif ($planning && $elsewhere !== null) {
                $work['parked_branch'] = null;
            }
            // the slot is taken last before the claim, so a start that claims the card has its slot
            $reserved = false;
            if ($worktrees->stackEnabled() && in_array($card->stage(), ['planning', 'ready'], true) && $card->claim() === null && $worktrees->registry()->find($path) === null) {
                try {
                    $worktrees->registry()->allocate($path, ['project' => $worktrees->env()->project($path), 'repo' => $this->paths()->main, 'branch' => $branch, 'card' => $id]);
                    $reserved = true;
                } catch (StackFailed $e) {
                    throw new PolicyRefused("refused {$id}: {$e->getMessage()}");
                }
            }
            // before the claim: a claim that lands though its start fails or is killed is still this checkout's to finish
            self::mark($this->paths(), $id, $work['started']);
            try {
                $this->transitions()->start($id, $this->actor(), work: $work, force: (bool) $this->option('force'));
            } catch (Throwable $e) {
                // refused by an earlier start of this checkout whose claim landed although that start failed: the claim
                // round has just pulled it, so that start is finished instead
                $landed = $e instanceof PolicyRefused || $e instanceof LostClaim ? $this->store()->card($id) : null;
                $resumed = $landed !== null && $this->resumable($landed, $worktrees) && in_array($landed->work()['started'] ?? null, self::marks($this->paths(), $id), true);
                if ($reserved && ! ($resumed && $this->paths()->main.'/'.$landed->work()['worktree'] === $path)) {
                    $worktrees->registry()->release($path);
                }
                if (! $resumed) {
                    throw $e;
                }
                $card = $landed;
            }
        }
        if ($resumed) {
            $work = $card->work();
            $path = $this->paths()->main.'/'.$work['worktree'];
            $branch = (string) $work['branch'];
            $parked = null;
            if (! $worktrees->isWorktree($path) && file_exists($path)) {
                throw new PolicyRefused("{$work['worktree']} exists but is not a worktree; remove it, then run `kanban start {$id}` again");
            }
            // finished here, the start is this checkout's from now on
            self::mark($this->paths(), $id, (string) ($work['started'] ?? ''));
        }

        $merged = [];
        try {
            if (! $worktrees->isWorktree($path)) {
                $worktrees->prune();
                $worktrees->add($path, $branch);
                // a planner reads the parked work as it is; the worker's start merges main into it
                if ($branch === $parked && ! $planning) {
                    $merged = $this->mergeMain($worktrees, $path, $id);
                }
            }
            // a planner revises the card's earlier plan in place; a worker reads the plan from the card
            if ($planning && ($plan = $card->plan()) !== null && ! is_file($path.'/.tmp/plan.md')) {
                @mkdir($path.'/.tmp', 0775, true);
                file_put_contents($path.'/.tmp/plan.md', rtrim($plan)."\n");
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
            $this->fault("{$id} stays in ".($planning ? 'planning' : 'doing').", blocked: {$reason}");
            $this->fault("run `kanban start {$id}` again once that is fixed, or clean up with `kanban stop {$id} --to=".($planning ? 'backlog' : 'ready').' --force`');
            throw $e;
        }

        $this->store()->update($id, function (array $data) use ($work) {
            $data['work'] = $work;
            if (str_starts_with((string) ($data['blocked'] ?? ''), 'start failed:')) {
                $data['blocked'] = null;
            }

            return $data;
        }, $this->actor());

        $this->say(($resumed ? 'resumed' : 'started').($planning ? ' planning' : '')." {$id}");
        $this->say("worktree {$path}");
        $this->say("branch {$branch}".($branch === $parked ? ' (parked branch reused)' : '')
            .($elsewhere !== null ? " (its parked branch {$elsewhere} is not on this machine: from {$worktrees->mainBranch()})" : ''));
        foreach ($merged as $line) {
            $this->say($line);
        }
        if ($work['stack'] !== null) {
            $stack = $work['stack'];
            $this->say("stack {$stack['project']} slot {$stack['slot']}".($stack['url'] !== null ? " {$stack['url']}" : ''));
            $this->say('ports '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($stack['ports']), $stack['ports'])));
            $this->say('starting: the '.($planning ? 'planner' : 'worker').' runs `vendor/bin/kanban stack wait` before using it');
        } else {
            $this->say('stack none (stack.compose_file unset or missing)');
        }
        foreach (Dependencies::problems($this->paths()->main, (array) ($this->setting('worktrees.copy') ?? [])) as $problem) {
            $this->say("warning: {$problem}");
        }
        $this->say(Worktrees::spawnLine($card, $planning ? 'kanban-planner' : 'kanban-worker', $path));
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
     * The `work.started` of this checkout's starts of $id, oldest first: `kanban run`, and a start refused by its own earlier
     * claim, finish only these.
     *
     * @return list<string>
     */
    public static function marks(Paths $paths, string $id): array
    {
        $handle = @fopen($paths->runtime(self::STARTED.'/'.$id), 'r');
        if ($handle === false) {
            return [];
        }
        try {
            flock($handle, LOCK_SH);

            return array_values(array_filter(array_map(trim(...), explode("\n", (string) stream_get_contents($handle)))));
        } finally {
            fclose($handle);
        }
    }

    /** Adds $started to this checkout's starts of $id. */
    private static function mark(Paths $paths, string $id, string $started): void
    {
        $handle = $started === '' ? false : @fopen($paths->ensureRuntime(self::STARTED).'/'.$id, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $marks = array_values(array_filter(array_map(trim(...), explode("\n", (string) stream_get_contents($handle)))));
            if (! in_array($started, $marks, true)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode("\n", [...$marks, $started])."\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A card in doing (or held in planning) on this machine whose start was cut short (killed, or failed and fixed since): its
     * worktree or stack is missing, or a failed start blocks it.
     */
    private function resumable(Card $card, Worktrees $worktrees): bool
    {
        $work = $card->work() ?? [];
        if (! $card->atWork() || $card->stage() === 'review' || ! is_string($work['worktree'] ?? null) || ! is_string($work['branch'] ?? null) || ($work['host'] ?? null) !== gethostname()) {
            return false;
        }

        return str_starts_with((string) $card->blocked(), 'start failed:') || ! $worktrees->isWorktree($this->paths()->main.'/'.$work['worktree'])
            || ($worktrees->stackEnabled() && ($work['stack'] ?? null) === null);
    }
}
