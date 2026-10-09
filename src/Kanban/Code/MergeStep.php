<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Console\Standalone;
use PetarSpasic\LaravelHouse\Kanban\Policy\MergeQueue;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Gates;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeLease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Waiting;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/**
 * One merge of the merge queue, what `kanban finish` runs: the card's approved head and main's tip (the remote's, else
 * local main) pinned, merged in main, checked in the merge clone's stack (installs, gates, `finish.check`), pushed to the
 * remote's main as a fast-forward, the card done, its clone torn down and this machine's main checkout following. A
 * conflict or a red check is the merger's (exit 12); the lease is held meanwhile, and the next `finish` goes on from
 * merge.json. Main never moves on red: a push that origin rejects merges again on the new main.
 */
final class MergeStep
{
    /** Merged and done, then a step after the push failed; or main could not be followed before the merge. */
    public const STEP_FAILED = 10;

    /** The queue's refusals (refusal()): about the queue, never a card. */
    public const NO_SUITE = 'finish.check names no suite: no card merges until it does (config/kanban.php)';

    public const NO_STACK = 'the merge queue runs its checks in the merge stack: set stack.compose_file';

    /** The merger's turn: a conflict, or a red check. The lease stays held. */
    public const MERGER = 12;

    /** The card left the queue: sent back, held on main red, its approval stale, or the merger's `back` or `main` applied. */
    public const RETURNED = 13;

    /** Rounds of one merge (a push origin rejected, a clone whose history was rewritten) before it gives up. */
    private const ROUNDS = 3;

    /** Merger results before a merge that stays red goes back to the worker. */
    public const MERGER_ROUNDS = 3;

    /** Unexpected failures in one phase before the card is blocked. */
    private const ATTEMPTS = 3;

    /** Files the merger gate refuses (Applier::mergerGate): a conflict in them is the owner's. */
    private const FENCED = ['.claude/', 'config/kanban.php'];

    private readonly MergeState $state;

    private readonly MergeLease $lease;

    private readonly MergeClone $clone;

    private readonly Worktrees $worktrees;

    private readonly Git $git;

    /** Main on the remote, while there is one. */
    private readonly ?MainPush $push;

    private readonly MainPush $origin;

    private readonly string $main;

    /** A step before the merge (following main) failed: a merge that lands after it exits 10. */
    private bool $faulted = false;

    /** @var list<string> the foreign cards this machine may pass over (MergeLease::observe) */
    private array $skippable = [];

    /** @var array<string, mixed>|null main's config as the main checkout has it now: what the checks run */
    private ?array $mainConfig = null;

    /**
     * @param  array<string, mixed>  $config  main's `kanban` config
     * @param  Closure(string): void  $say
     * @param  Closure(string): void  $fault
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private readonly Store $store,
        private readonly Actor $actor,
        private readonly Closure $say,
        private readonly Closure $fault,
        private readonly bool $rebuild = true,
    ) {
        $this->state = new MergeState($paths);
        $this->lease = new MergeLease($store, $paths, $actor);
        $this->clone = new MergeClone($paths, $config);
        $this->worktrees = new Worktrees($paths, $config);
        $this->git = new Git($paths->main);
        $this->main = $this->worktrees->mainBranch();
        $this->origin = MainPush::of($paths, $config);
        $this->push = $this->origin->hasRemote() ? $this->origin : null;
    }

    /** Merges $id, or the merge of this checkout in flight, or this machine's next card in the queue. */
    public function run(?string $id): int
    {
        $state = $this->state->read();
        // a push in flight is settled whatever the config says now: asking origin needs no suite
        if (($state['phase'] ?? null) !== MergeState::PUSHING) {
            $this->requireQueue();
        }
        $snapshot = $this->store->snapshot();
        if ($id !== null) {
            $card = $snapshot->resolve($id);
            if ($state !== null && $state['card'] !== $card->id()) {
                throw new Waiting("merging {$state['card']} here: `kanban finish {$state['card']}` goes on with it");
            }
        } elseif ($state !== null) {
            $card = $snapshot->resolve($state['card']);
        } else {
            $this->store->maybeSync(true);
            $next = $this->queue($this->store->snapshot());
            $card = $next['card'] ?? throw new Waiting('nothing to merge here: '.$next['why']);
        }
        if ($card->stage() === 'done') {
            return $this->tidy($card);
        }
        try {
            return $this->merge($card);
        } catch (LostClaim $e) {
            // its merger works on a merge that is over, in the clone the next merge resets
            $this->stopAgents($card->id());
            $this->state->clear();

            throw $e;
        } catch (LockTimeout $e) {
            throw $e;
        } catch (RemoteFailed $e) {
            // a push in flight is settled by the next finish, which asks origin first; a merger's work outlasts an outage
            $state = $this->state->read();
            if (($state['phase'] ?? null) !== MergeState::PUSHING && ! $this->mergerWork($state)) {
                $this->leave($card->id());
            } elseif ($this->mergerWork($state)) {
                ($this->fault)("{$card->id()}: {$e->getMessage()}; the merge and its lease stay: the next `kanban finish {$card->id()}` goes on with it");
            }

            throw $e;
        } catch (StackFailed $e) {
            if (! $this->kept($card, $e->getMessage())) {
                $this->leave($card->id());
            }

            throw $e;
        } catch (Waiting|PolicyRefused $e) {
            $this->leave($card->id());

            throw $e;
        } catch (Throwable $e) {
            return $this->failed($card, $e);
        }
    }

    /** `finish --abort`: the merger and the lease let go, the merge clone back at the base; the card stays queued. */
    public function abort(?string $id): int
    {
        $state = $this->state->read();
        if ($state === null) {
            ($this->say)('no merge runs here');

            return 0;
        }
        $card = $id === null ? $state['card'] : $this->store->card($id)->id();
        if ($card !== $state['card']) {
            throw new Waiting("merging {$state['card']} here, not {$card}");
        }
        if ($state['phase'] === MergeState::PUSHING) {
            throw new Waiting('a push of '.substr((string) ($state['merged'] ?? ''), 0, 7)." is in flight: `kanban finish {$card}` settles it");
        }
        $this->stopAgents($card);
        $this->lease->release();
        $this->reset($card);
        $this->state->clear();
        ($this->say)("merge of {$card} aborted: the merge lease is free, the card stays queued");

        return 0;
    }

    /** `finish --follow`: this machine's main checkout brought to the remote's main, with its after-steps. */
    public function follow(): int
    {
        if ($this->push === null) {
            ($this->say)("no remote: the main checkout is {$this->main} itself");

            return 0;
        }
        $remote = $this->push->fetchMain() ?? throw new RemoteFailed("the remote has no {$this->main}");

        return $this->checkout()->follow($remote) ? 0 : self::STEP_FAILED;
    }

    private function merge(Card $card): int
    {
        $id = $card->id();
        $state = $this->state->read();
        if ($state !== null && $state['phase'] !== MergeState::ACQUIRING) {
            if (isset($state['lost'])) {
                throw new LostClaim((string) $state['lost']);
            }
            if ($state['phase'] === MergeState::RELEASING) {
                $this->lease->release();
                ($this->say)("merge lease of {$id} given back");

                return 0;
            }
            if ($state['phase'] === MergeState::RELEASED) {
                $this->lease->release();
                $this->reset($id);
                ($this->say)("{$id} left the merge queue: its merger's result is on the card");

                return self::RETURNED;
            }
            if ($state['phase'] === MergeState::PUSHING) {
                return $this->settle($card, $state);
            }
            try {
                $this->lease->beat(fence: true);
            } catch (RemoteFailed $e) {
                // the lease stands until it expires, and nothing is pushed without a fence beat that lands
                ($this->fault)("{$id}: {$e->getMessage()}; the merge and its lease stay: the next `kanban finish {$id}` goes on with it");

                return RemoteFailed::EXIT;
            }
            $this->beater($state['lease']);
            if (in_array($state['phase'], [MergeState::CONFLICT, MergeState::RED], true)) {
                // the merger's shell runs in the merge stack: brought up after a reboot, never rebuilt under its work
                $this->clone->ensure(rebuild: false);

                return $this->merger($card, $state);
            }
            $this->clone->ensure();
            if ($state['phase'] !== MergeState::CHECKS) {
                return $this->round($card);
            }
            if (($head = $this->gated($card, $state)) === null) {
                $this->again($card, 'the merge clone holds commits no check ran on');

                return $this->round($card);
            }
            // what is checked is what is committed, nothing a merger left beside it
            $this->clone->checkout($head);

            return $this->checks($card, $head);
        }

        // 1. the card, as the board has it now
        $this->store->maybeSync(true);
        $snapshot = $this->store->snapshot();
        $card = $snapshot->resolve($id);
        $this->queue($snapshot);
        $this->queued($card, $snapshot);

        // 2. its approved head, pinned: a commit the branch gets later is not merged
        $work = $card->work() ?? [];
        $branch = (string) ($work['branch'] ?? '');
        $clone = $this->cardClone($card);
        if ($clone !== null) {
            $this->worktrees->sync($clone, $branch);
        }
        if ($branch === '' || ! $this->worktrees->branchExists($branch)) {
            throw new PolicyRefused("{$id}: its branch '{$branch}' is not on this machine");
        }
        $head = $this->worktrees->head('refs/heads/'.$branch);
        $approved = (string) $work['approved']['head'];
        if ($head !== $approved) {
            $this->store->update($id, function (array $data) use ($head) {
                $data['work']['approved'] = null;
                $data['log'][] = MergeState::entry('stale', ['head' => $head]);

                return $data;
            }, $this->actor);
            $this->letGo();
            ($this->say)("{$id}: its branch is at ".substr($head, 0, 7).', past its approval of '.substr($approved, 0, 7).'; an evaluator judges it again');

            return self::RETURNED;
        }
        $this->clone->pin($id, 'card', $approved);
        $this->localMain($id, $approved);

        // 3. the merge lease
        $lease = $this->lease->acquire($card, fn (Snapshot $s) => $this->queued($s->resolve($id), $s, $approved),
            $this->push === null ? null : $this->push->landed(...));
        $this->beater($lease['id']);
        $card = $this->store->snapshot()->resolve($id);
        if ($card->stage() === 'done') {
            return $this->tidy($card);
        }
        ($this->say)("merge lease taken for {$id}");

        // 4. the merge clone and its stack
        $this->clone->ensure();

        return $this->round($card);
    }

    /** Steps 5 and 6: main's tip pinned as the base, the card merged into it in main, then checked or handed to the merger. */
    private function round(Card $card): int
    {
        $id = $card->id();
        $approved = (string) $this->clone->pinned($id, 'card');
        if ($this->push !== null) {
            $base = $this->push->fetchMain() ?? throw new RemoteFailed("the remote has no {$this->main}: push it once");
            $this->faulted = ! $this->checkout()->follow($base) || $this->faulted;
            // a gate or suite command another machine just merged counts from this merge on
            $this->mainConfig = null;
            if (($why = self::refusal($this->mainConfig(), $this->paths->main)) !== null) {
                throw new PolicyRefused($why);
            }
        } else {
            $this->localMain($id, $approved);
            $base = $this->worktrees->head('refs/heads/'.$this->main);
        }
        $this->clone->pin($id, 'base', $base);
        $state = $this->state->set(['phase' => MergeState::MERGING, 'base' => $base, 'head' => $approved]);

        if ($this->git->attempt(['merge-base', '--is-ancestor', $approved, $base])->ok()) {
            return $this->onBase($card, $approved, $base);
        }

        $tree = $this->git->attempt(['--attr-source='.$base, 'merge-tree', '--write-tree', '--name-only', '--no-messages', '-z', $base, $approved]);
        $lines = explode("\0", rtrim($tree->out, "\0"));
        if ($tree->code === 1) {
            return $this->conflicted($card, array_values(array_filter(array_slice($lines, 1), fn (string $l) => $l !== '')), $state);
        }
        if (! $tree->ok() || preg_match('/^[0-9a-f]{40,64}$/', $lines[0]) !== 1) {
            throw new GitFailed("merging {$id} failed: ".Worktrees::tail($tree->err ?: $tree->out));
        }
        $merge = trim($this->git->run(['commit-tree', $lines[0], '-p', $base, '-p', $approved, '-m', "{$id}: {$card->title()}"]));
        $this->clone->pin($id, 'merge', $merge);
        if (($markers = (new MergeCheck($this->git))->markers($base, $approved)) !== []) {
            return $this->back($card, 'leftover conflict markers: '.implode(', ', array_slice($markers, 0, 5)), ['files' => array_slice($markers, 0, 20)]);
        }
        $this->clone->fetchPins($id);
        $this->clone->checkout('refs/merge/merge');
        $this->state->set(['phase' => MergeState::CHECKS, 'merge_commit' => $merge]);

        return $this->checks($card, $merge);
    }

    /**
     * The merge stopped on conflicts. In files the merger may not write, the card is blocked for the owner; otherwise
     * the merge is made again in the merge clone by its own git, where rerere may resolve it, else it waits for the merger.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $state
     */
    private function conflicted(Card $card, array $files, array $state): int
    {
        $id = $card->id();
        $fenced = array_values(array_filter($files, fn (string $f) => array_filter(self::FENCED, fn (string $p) => $f === $p || (str_ends_with($p, '/') && str_starts_with($f, $p))) !== []));
        if ($fenced !== []) {
            $why = 'merge conflict in files the merger may not edit: '.implode(', ', $fenced);
            $this->store->update($id, fn (array $data) => ['blocked' => mb_substr($why, 0, 500)] + $data, $this->actor);

            throw new PolicyRefused("{$id}: {$why}; resolve it on the card's branch, then unblock it (the kanban skill's gotchas.md says how)");
        }
        $this->clone->fetchPins($id);
        $message = "{$id}: {$card->title()}";
        $unmerged = $this->clone->conflict($message);
        // a resolution git remembers was never through the merger gate: one a merger staged and was refused, or planted
        $resolved = $unmerged === [] ? $this->clone->take($id, 'result') : null;
        if ($resolved !== null && ($why = $this->applier()->mergerGate($state['base'], $state['head'], $resolved)) !== []) {
            ($this->say)("{$id}: the resolution git remembered for ".implode(', ', $files).' would '.implode('; ', $why).': forgotten, the merger resolves it');
            $unmerged = $this->clone->forget($message);
            $resolved = null;
        }
        if ($resolved !== null) {
            ($this->say)("{$id}: conflicts in ".implode(', ', $files).' resolved as before (rerere)');
            $this->clone->checkout($resolved);
            $this->state->set(['phase' => MergeState::CHECKS, 'merge_commit' => $resolved]);

            return $this->checks($card, $resolved);
        }
        $state = $this->state->set(['phase' => MergeState::CONFLICT, 'conflicts' => $unmerged]);
        $this->log($id, MergeState::entry('conflict', ['files' => $unmerged, 'base' => $state['base'], 'round' => $state['round']]));

        return $this->merger($card, $state);
    }

    /**
     * Step 7: installs, the gates and the suite on $sha, the merged tree checked out in the merge clone, in the merge
     * stack; green goes on to the push.
     */
    private function checks(Card $card, string $sha): int
    {
        $id = $card->id();
        $tick = function (): void {
            $state = (array) $this->state->read();
            if (isset($state['lost'])) {
                throw new LostClaim((string) $state['lost']);
            }
            // the beater of the merge before may still have held its lock as this one took the lease
            if (isset($state['lease'])) {
                $this->beater((string) $state['lease']);
            }
        };
        $tick();
        $replay = $this->state->read()['replay'] ?? null;
        if (is_array($replay)) {
            $sha = $this->replayed($card, $sha, $replay);
            $this->state->set(['replay' => null]);
        }
        // what is pushed is this commit and nothing after it: the one this merge chose, never what the clone's branch
        // says now, which anything running in the merge stack can move
        $this->state->set(['checked' => $sha]);
        try {
            if (($entry = $this->worktrees->freshen($this->clone->path)) !== null) {
                $this->worktrees->await($entry);
            }
        } catch (StackFailed $e) {
            // the stack ran on the base: one the merged tree's docker files break is counted, the third time blocks the card
            if ($this->strike($card, 'stack', $e->getMessage())) {
                $this->leave($id);
            }

            throw $e;
        }
        $failure = $this->installs($tick);
        if ($failure === null && ($gate = (new Gates($this->mainConfig()))->failed($this->clone->path, $tick)) !== null) {
            $failure = ['step' => 'gate', 'command' => $gate['run'], 'exit' => $gate['code'], 'tail' => $gate['tail']];
        }
        $failure ??= $this->suite($card, $tick);
        if (is_int($failure)) {
            return $failure;
        }
        if ($failure !== null) {
            return $this->red($card, $failure);
        }
        $tick();
        ($this->say)("{$id}: gates and finish.check pass on the merged tree");

        return $this->pushing($card);
    }

    /**
     * Each `finish.install` lockfile of the merged tree whose content was not installed in the merge clone yet: its
     * command in its directory, in the merge stack. The first failure, or null.
     *
     * @return array{step: string, command: string, exit: ?int, tail: string}|null
     */
    private function installs(Closure $tick): ?array
    {
        $install = $this->finish()['install'];
        $file = $this->paths->runtime(MergeClone::INSTALLED);
        $installed = Runtime::readJson($file) ?? [];
        // a lockfile's blob id in the index names what it holds
        foreach (array_filter(explode("\0", Git::untrusted($this->clone->path)->attempt(['ls-files', '-s', '-z'])->out)) as $line) {
            [$mode, $hash] = explode(' ', $line);
            $lockfile = substr($line, strpos($line, "\t") + 1);
            if (! isset($install[basename($lockfile)]) || ! in_array($mode, ['100644', '100755'], true) || ($installed[$lockfile] ?? null) === $hash
                || ($dir = CloneFile::within($this->clone->path, dirname($lockfile))) === null) {
                continue;
            }
            $command = (string) $install[basename($lockfile)];
            // an install that fails or is killed part way leaves what neither content installs
            unset($installed[$lockfile]);
            Runtime::writeJson($file, $installed);
            if (($failed = (new Suite($this->mainConfig()))->run($this->clone->path, $command, $tick, $dir, 600)) !== null) {
                return ['step' => 'install', ...$failed];
            }
            $installed[$lockfile] = $hash;
            Runtime::writeJson($file, $installed);
        }

        return null;
    }

    /**
     * `finish.check`, each command; one that fails is run again on the base alone when that is a fair test (the same
     * dependencies and docker files): red there too, main is red, and the queue waits for its fix. A card filed for main
     * red merges once its own command passes, whatever else fails on the base too. A command not found (exit 126, 127)
     * on the base too is the project's to fix (StackFailed, the merge let go); on the merged tree alone, the merger's.
     *
     * @return array{step: string, command: string, exit: ?int, tail: string, base_rerun: string}|int|null the failure for the merger, an exit, or null
     */
    private function suite(Card $card, Closure $tick): array|int|null
    {
        $suite = new Suite($this->mainConfig());
        $own = MergeQueue::fixes($card);
        $state = (array) $this->state->read();
        $checked = (string) $state['checked'];
        foreach ($suite->commands() as $command) {
            if (($failed = $suite->run($this->clone->path, $command, $tick)) === null) {
                continue;
            }
            $missing = in_array($failed['exit'], [126, 127], true);
            if ($own === $command && ! $missing) {
                return $this->back($card, "`{$command}` still fails:\n".mb_substr($failed['tail'], -1500), ['command' => $command]);
            }
            $fair = $this->fairRerun((string) $state['base'], $checked);
            $onBase = null;
            if ($fair) {
                $this->clone->checkout('refs/merge/base');
                $onBase = $suite->run($this->clone->path, $command, $tick);
                $this->clone->checkout($checked);
            }
            if ($missing && in_array($onBase['exit'] ?? null, [126, 127], true)) {
                // no card or merger fixes it: the merge goes, uncounted, its merger's work with it
                $this->leave($card->id());
                $where = ($this->worktrees->stackRecord($this->clone->path)['shell'] ?? null) === 'container' ? 'in the app container' : 'on this machine';
                throw new StackFailed("finish.check: `{$command}` is not found {$where} (exit {$failed['exit']}), on {$this->main} either; it runs there");
            }
            if ($missing) {
                // the merged tree lost a command the base has, or no fair rerun tells: the merger's to judge
                return ['step' => 'suite', ...$failed, 'base_rerun' => match (true) {
                    ! $fair => 'skipped',
                    $onBase === null => 'passed',
                    default => 'failed',
                }];
            }
            if ($own !== null && ($onBase !== null || in_array($command, (array) ($state['on_main'] ?? []), true))) {
                ($this->say)("{$card->id()}: `{$command}` fails on {$this->main} too; it merges once its own command passes");

                continue;
            }
            if ($onBase !== null) {
                return $this->mainRed($card, $failed, (string) $state['base']);
            }

            return ['step' => 'suite', ...$failed, 'base_rerun' => $fair ? 'passed' : 'skipped'];
        }

        return null;
    }

    /** Whether a command run on the base in the merge stack tests the base as it is: the merge changed no dependency or docker file. */
    private function fairRerun(string $base, string $checked): bool
    {
        $files = array_filter(explode("\0", Git::untrusted($this->clone->path)->attempt(['diff', '--no-ext-diff', '--no-textconv', '--name-only', '-z', $base, $checked])->out));
        $locks = array_keys($this->finish()['install']);

        return MergeCheck::rebuildFiles($files, $this->mainConfig()['stack']['compose_file'] ?? null) === []
            && array_filter($files, fn (string $f) => in_array(basename($f), $locks, true)) === [];
    }

    /**
     * Main is red: the failing command fails on the base alone. The card filed for it is noted or filed, the merge ends
     * and the queue waits until main moves (MergeQueue::held).
     *
     * @param  array{command: string, exit: ?int, tail: string}  $failed
     */
    private function mainRed(Card $card, array $failed, string $base): int
    {
        $red = $this->applier()->failingOnMain($card, $failed['command'], $failed['tail'], $this->actor);
        $this->log($card->id(), MergeState::entry('main', ['red' => $red, 'command' => $failed['command'], 'base' => $base]));
        $this->attempt($card->id(), null);
        $this->leave($card->id());
        ($this->say)("{$card->id()} waits for {$red}: `{$failed['command']}` fails on {$this->main} too (".substr($base, 0, 7).')');

        return self::RETURNED;
    }

    /**
     * A check failed on the merged tree: the merger's to judge, or after MERGER_ROUNDS of its results, the worker's.
     *
     * @param  array{step: string, command: string, exit: ?int, tail: string, base_rerun?: string}  $failure
     */
    private function red(Card $card, array $failure): int
    {
        $failure += ['base_rerun' => 'skipped'];
        $state = $this->state->set(['phase' => MergeState::RED, 'failure' => $failure]);
        $this->log($card->id(), MergeState::entry('red', ['step' => $failure['step'], 'command' => $failure['command'], 'exit' => $failure['exit'],
            'base' => $state['base'], 'round' => $state['round'], 'base_rerun' => $failure['base_rerun']]));
        if (($state['merger_rounds'] ?? 0) >= self::MERGER_ROUNDS) {
            return $this->back($card, 'the merge stays red after '.self::MERGER_ROUNDS." merger rounds: `{$failure['command']}`\n".mb_substr($failure['tail'], -1500), ['command' => $failure['command']]);
        }

        return $this->merger($card, $state);
    }

    /**
     * The card goes back to its worker with $note, in one write with its `merge` entry; the merge ends.
     *
     * @param  array<string, mixed>  $fields  the entry's optional fields
     */
    private function back(Card $card, string $note, array $fields = []): int
    {
        $this->store->update($card->id(), function (array $data) use ($note, $fields) {
            $data['log'][] = MergeState::entry('back', ['note' => $note] + $fields);

            return Transitions::sentBack($data, 'merge', $note);
        }, $this->actor);
        $this->attempt($card->id(), null);
        $this->leave($card->id());
        ($this->say)("{$card->id()} review→doing: ".strtok($note, "\n"));

        return self::RETURNED;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function merger(Card $card, array $state): int
    {
        ($this->say)("{$card->id()}: ".MergeState::turn($state)."; the merger's turn, the merge lease stays held");
        ($this->say)('merger: '.Worktrees::spawnLine($card, AgentRun::MERGER, $this->clone->path));

        return self::MERGER;
    }

    /** Step 8 and 9: what the clone holds, into main and checked to be the merge plus the merger's fixes; fenced on the lease. */
    private function pushing(Card $card): int
    {
        $id = $card->id();
        $state = (array) $this->state->read();
        $result = $this->clone->take($id, 'result');
        if ($result !== ($state['checked'] ?? null)) {
            $this->again($card, 'the merge clone holds commits no check ran on');

            return $this->round($card);
        }
        $merge = (string) $state['merge_commit'];
        $parents = explode(' ', (string) $this->git->line(['rev-list', '--parents', '-n', '1', $merge]));
        if ($parents !== [$merge, $state['base'], $state['head']] || Applier::fixes($this->git, $merge, $result) === null) {
            $this->again($card, 'the merge clone holds another history than the merge and its fixes');

            return $this->round($card);
        }
        $this->state->set(['phase' => MergeState::PUSHING, 'merged' => $result]);
        $this->lease->beat($result, fence: true);
        if (($why = $this->left($state)) !== null) {
            $this->leave($id);
            ($this->say)("{$id} left the merge queue before its push: {$why}");

            return self::RETURNED;
        }

        return $this->push($this->store->snapshot()->resolve($id), $result);
    }

    /** Step 10: main moves only by a fast-forward to $merged; a main that moved meanwhile merges again. */
    private function push(Card $card, string $merged): int
    {
        if ($this->push !== null) {
            try {
                $pushed = $this->push->pushSha($merged);
            } catch (RemoteFailed $e) {
                // an answer from origin: the push did not land, so another machine may merge meanwhile
                if (! $this->push->landed($merged)) {
                    $this->strike($card, MergeState::PUSHING, $e->getMessage());
                    $this->leave($card->id());

                    throw new RemoteFailed("{$card->id()}: {$e->getMessage()}; the merge lease goes back, the card stays queued");
                }
                $pushed = 'ok';
            }
            if ($pushed === 'rejected') {
                $this->again($card, "{$this->main} moved on the remote");

                return $this->round($card);
            }

            return $this->done($card, $merged);
        }
        if (($why = $this->worktrees->notOnMain()) !== null) {
            throw new Waiting("main checkout not moved: {$why}; the merge fast-forwards it");
        }
        $state = (array) $this->state->read();
        $old = $this->worktrees->head('refs/heads/'.$this->main);
        if ($old !== $state['base']) {
            $this->again($card, "{$this->main} moved");

            return $this->round($card);
        }
        $ff = $this->git->attempt(['merge', '--ff-only', '-q', $merged]);
        if (! $ff->ok()) {
            // after the checks: counted, so a checkout that stays in the way blocks the card instead of a check a pass
            $why = 'main checkout not moved: '.Worktrees::tail($ff->err ?: $ff->out);
            $this->strike($card, MergeState::PUSHING, $why);

            throw new Waiting("{$why}; remove, commit or stash the files in the way, then `kanban finish {$card->id()}`");
        }

        return $this->done($card, $merged, $old);
    }

    /**
     * Steps 11 and 12: the card done, the lease free, its clone torn down, the main checkout following. Without a remote
     * the after-steps of the fast-forward run first: a `finish` killed before they ended goes on from merge.json.
     *
     * @param  string|null  $from  where local main was before a local fast-forward (no remote)
     */
    private function done(Card $card, string $merged, ?string $from = null): int
    {
        $id = $card->id();
        $sha7 = substr($merged, 0, 7);
        $followed = $from === null || $this->checkout()->after($from, $merged);
        try {
            $card = $this->transitions()->finish($id, $merged, $this->actor);
            ($this->say)("merged {$id} into {$this->main} {$sha7}");
            ($this->say)("{$id} review→done");
        } catch (PolicyRefused) {
            $card = $this->log($id, MergeState::entry('landed', ['merge' => $merged]));
            ($this->say)("merged {$id} into {$this->main} {$sha7}");
            $this->faulted = true;
            ($this->fault)("{$id} reached {$this->main} but left review: it is {$card->stage()}");
        }
        $this->letGo();
        $this->attempt($id, null);
        // a card that left review keeps what it has here: its worker may be at it again
        $ok = $card->stage() !== 'done' || $this->teardown($card, $merged);
        $ok = ($from === null ? $this->checkout()->follow($merged) : $followed) && $ok;
        $this->clone->unpin($id);

        return $ok && ! $this->faulted ? 0 : self::STEP_FAILED;
    }

    /** A merge whose push may have landed (phase pushing): asked of the remote before anything else. */
    private function settle(Card $card, array $state): int
    {
        $merged = (string) $state['merged'];
        $landed = $this->push === null
            ? $this->git->attempt(['merge-base', '--is-ancestor', $merged, 'refs/heads/'.$this->main])->ok()
            : $this->push->landed($merged);
        if ($landed) {
            // without a remote main moved from the base here: its after-steps may not have run
            return $this->done($card, $merged, $this->push === null ? (string) $state['base'] : null);
        }
        $this->requireQueue();
        $this->lease->beat(fence: true);
        $this->beater($state['lease']);
        $this->clone->ensure();
        $this->again($card, 'its push did not land');

        return $this->round($card);
    }

    /**
     * The merge clone's HEAD when it is one this merge may check: the one its checks ran on, or the head of the merger
     * result just applied (which passed the merger gate). Anything else was committed past them: null.
     *
     * @param  array<string, mixed>  $state
     */
    private function gated(Card $card, array $state): ?string
    {
        $head = $this->clone->head();
        if ($head === ($state['checked'] ?? null)) {
            return $head;
        }
        foreach (array_reverse($this->store->card($card->id())->log()) as $entry) {
            if (($entry['event'] ?? null) === 'merge') {
                return in_array($entry['result'] ?? null, ['resolved', 'fixed'], true) && ($entry['head'] ?? null) === $head ? $head : null;
            }
        }

        return null;
    }

    /**
     * $sha with the merger's fixes of an earlier round ($replay, through the merger gate then) cherry-picked onto it, as
     * main reads it back: one commit of one parent for each fix, and the whole through the merger gate again on this
     * round's base. Else $sha alone, checked out again.
     *
     * @param  array{from: string, to: string}  $replay
     */
    private function replayed(Card $card, string $sha, array $replay): string
    {
        $state = (array) $this->state->read();
        if ($this->clone->replay($replay['from'], $replay['to'])) {
            $head = $this->clone->take($card->id(), 'result');
            if (Applier::fixes($this->git, $sha, $head) === (int) $this->git->line(['rev-list', '--count', "{$replay['from']}..{$replay['to']}"])
                && $this->applier()->mergerGate((string) $state['base'], (string) $state['head'], $head) === []) {
                return $head;
            }
        }
        ($this->say)("{$card->id()}: its merger's fixes do not apply on the new {$this->main}: checked without them");
        $this->clone->checkout($sha);

        return $sha;
    }

    /** The card's approved head is already on the base (a push of it landed and was never recorded): done, nothing merged. */
    private function onBase(Card $card, string $approved, string $base): int
    {
        $first = strtok((string) $this->git->line(['rev-list', '--first-parent', '--ancestry-path', '--reverse', "{$approved}..{$base}"]), "\n");
        // a card main was fast-forwarded to is its own merge
        $merge = $first === false || $this->git->line(['rev-parse', '--verify', '-q', "{$first}^1"]) === $approved ? $approved : $first;
        $this->store->update($card->id(), function (array $data) use ($merge) {
            $data = Transitions::finished($data, $merge);
            $data['log'][] = MergeState::entry('landed', ['merge' => $merge]);

            return $data;
        }, $this->actor);
        ($this->say)("{$card->id()} is on {$this->main} already (".substr($merge, 0, 7).'): done');
        $this->letGo();
        $this->attempt($card->id(), null);
        $ok = $this->teardown($this->store->card($card->id()), $merge);
        $this->clone->unpin($card->id());

        return $ok ? 0 : self::STEP_FAILED;
    }

    /** A done card this machine still holds a clone or branch of: they go. */
    private function tidy(Card $card): int
    {
        if (($this->state->read()['card'] ?? null) === $card->id()) {
            $this->letGo();
        }
        try {
            $this->push?->fetchMain();
        } catch (RemoteFailed $e) {
            ($this->fault)("fetching {$this->main}: {$e->getMessage()}");
        }
        $branch = (string) ($card->work()['branch'] ?? '');
        if ($this->cardClone($card) === null && ($branch === '' || ! $this->worktrees->branchExists($branch))) {
            ($this->say)("{$card->id()} is done; nothing of it is left here");

            return 0;
        }
        $ok = $this->teardown($card, (string) ($card->work()['merge'] ?? ''));
        $this->clone->unpin($card->id());

        return $ok ? 0 : self::STEP_FAILED;
    }

    /** The done card's stack, clone and branch: each step that fails is named, the next one runs. */
    private function teardown(Card $card, string $merge): bool
    {
        $branch = (string) ($card->work()['branch'] ?? '');
        $path = $this->cardClone($card);
        $ok = true;
        if ($path !== null) {
            $project = $this->worktrees->registry()->find($path)['project'] ?? null;
            $ok = $this->guard('stack down', function () use ($path, $project) {
                if (! $this->worktrees->down($path)) {
                    ($this->fault)("stack down failed for {$path}; the slot is kept until `kanban stack gc`");

                    return false;
                }
                ($this->say)($project === null ? 'stack none' : "stack down {$project}; slot released");

                return true;
            });
            $ok = $this->guard('removing the clone', function () use ($path, $branch) {
                $leftovers = $this->worktrees->leftovers($path);
                $this->worktrees->remove($path, $leftovers !== null, $branch === '' ? null : $branch);
                ($this->say)('removed worktree '.$this->paths->relative($path));
                $leftovers === null || ($this->say)($leftovers);

                return true;
            }) && $ok;
        }
        if ($branch !== '' && $this->worktrees->branchExists($branch)) {
            $ok = $this->guard('deleting the branch', function () use ($branch, $merge) {
                $head = $this->worktrees->head('refs/heads/'.$branch);
                if ($merge === '' || ! $this->git->attempt(['merge-base', '--is-ancestor', $head, $merge])->ok()) {
                    ($this->fault)("branch {$branch} kept: ".substr($head, 0, 7)." is not in the card's merge");

                    return false;
                }
                if (! $this->worktrees->deleteBranch($branch, true)) {
                    ($this->fault)("branch {$branch} kept: git branch -D refused");

                    return false;
                }
                ($this->say)("deleted branch {$branch}");

                return true;
            }) && $ok;
        }
        $this->worktrees->prune();

        return $ok;
    }

    /** The card's clone here: where `start` put it, else the clone under `.claude/worktrees/` on its branch (a retitled card). */
    private function cardClone(Card $card): ?string
    {
        $branch = (string) ($card->work()['branch'] ?? '');
        $paths = isset($card->work()['worktree']) ? [$this->paths->main.'/'.$card->work()['worktree']] : [];
        $paths[] = $this->paths->worktree($card->id(), $card->title());
        foreach ($paths as $path) {
            if (is_dir($path) && $this->worktrees->isClone($path)) {
                return $path;
            }
        }
        foreach (glob($this->paths->worktrees().'/*', GLOB_ONLYDIR) ?: [] as $path) {
            if ($branch !== '' && ! $this->worktrees->isMergeClone($path) && trim((string) CloneFile::read($path, '.git/HEAD')) === "ref: refs/heads/{$branch}") {
                return $path;
            }
        }

        return null;
    }

    /**
     * Why $card may not merge here now, thrown: not in the queue (PolicyRefused), or not its turn (Waiting). With $head,
     * also when its approval moved from it (the lease's check on the board it is taken on).
     */
    private function queued(Card $card, Snapshot $snapshot, ?string $head = null): void
    {
        $id = $card->id();
        $approved = $card->work()['approved'] ?? null;
        if ($card->stage() !== 'review') {
            throw new PolicyRefused("{$id} is {$card->stage()}, not review");
        }
        if (! is_string($approved['head'] ?? null) || ! is_string($approved['at'] ?? null)) {
            throw new PolicyRefused("{$id} has no approval: an evaluator approves it first");
        }
        if ($head !== null && $approved['head'] !== $head) {
            throw new PolicyRefused("{$id}: its approval moved to ".substr($approved['head'], 0, 7).' since the merge began');
        }
        if ($card->blocked() !== null) {
            throw new PolicyRefused("{$id} is blocked: {$card->blocked()}");
        }
        if (($busy = $this->busy($card)) !== null) {
            throw new Waiting("{$id}: {$busy}; `vendor/bin/kanban wait {$id}`");
        }
        if (($held = MergeQueue::held($card, $snapshot, $this->originMain())) !== null) {
            throw new Waiting("{$id} {$held}");
        }
        $next = $this->next($snapshot);
        if ($next['card']?->id() !== $id) {
            throw new Waiting("{$id} waits its turn: ".($next['card'] === null ? $next['why'] : "{$next['card']->id()} goes first"));
        }
    }

    /**
     * What `finish` without an id takes: this machine's next card in the queue and why no other, once this machine has
     * observed the board's lease and the front of the queue (which times a foreign card there, so call it on every pass);
     * how long the lease stood still here and whether that makes it expired.
     *
     * @return array{card: ?Card, why: string, expired: bool, stood: ?float}
     */
    public function queue(Snapshot $snapshot): array
    {
        $seen = $this->lease->observe($snapshot, $this->originMain(), $this->local(...), $this->busy(...));
        $this->skippable = $seen['skippable'];
        unset($seen['skippable']);

        return $seen;
    }

    /**
     * Why the merge in flight here ($state, merge.json) may not go on: its card left the queue (another stage, its
     * approval moved, a block), a `stop` takes it down, or another agent of it works. Null while it may.
     *
     * @param  array<string, mixed>  $state
     */
    public function left(array $state): ?string
    {
        $card = $this->store->snapshot()->card((string) $state['card']);

        return match (true) {
            $card === null => 'it is gone from the board',
            $card->stage() !== 'review' => "it is {$card->stage()}",
            ($card->work()['approved']['head'] ?? null) !== ($state['head'] ?? null) => 'its approval changed',
            $card->blocked() !== null => "it is blocked: {$card->blocked()}",
            (new AgentRun($this->paths, $this->config))->stopping($card->id()) => 'it is being stopped',
            default => $this->busy($card),
        };
    }

    /** @return array{card: ?Card, why: string} */
    private function next(Snapshot $snapshot): array
    {
        return MergeQueue::next($snapshot, $this->originMain(), $this->local(...), $this->busy(...), fn (Card $c) => in_array($c->id(), $this->skippable, true));
    }

    /** The card is this machine's to merge: its clone or branch is here, and no `stop` is taking it down. */
    private function local(Card $card): bool
    {
        $branch = (string) ($card->work()['branch'] ?? '');

        return ($this->cardClone($card) !== null || ($branch !== '' && $this->worktrees->branchExists($branch)))
            && ! (new AgentRun($this->paths, $this->config))->stopping($card->id());
    }

    /** Why the card cannot merge now: an agent of it other than its merger is at work. */
    private function busy(Card $card): ?string
    {
        foreach ((new AgentRun($this->paths, $this->config))->running() as $run) {
            if (($run['card'] ?? null) === $card->id() && ($run['type'] ?? null) !== AgentRun::MERGER) {
                return 'its '.str_replace('kanban-', '', (string) $run['type']).' is running';
            }
        }
        $runtime = new Runtime($this->paths);
        foreach ($runtime->agents() as $agent) {
            if (($agent['card'] ?? null) === $card->id() && ($agent['agent_type'] ?? null) !== AgentRun::MERGER && $runtime->state($agent) === 'live') {
                return 'an agent is still bound to it ('.($agent['agent_type'] ?? 'agent').')';
            }
        }

        return null;
    }

    /** Main's tip the queue's holds compare with: the remote's as last fetched, else local main. */
    public function originMain(): ?string
    {
        return $this->origin->known();
    }

    /** The next round on a new base: the merge made again, the merger's fixes replayed onto it. */
    private function again(Card $card, string $why): void
    {
        $state = (array) $this->state->read();
        $round = (int) $state['round'] + 1;
        if ($round > self::ROUNDS) {
            $this->leave($card->id());

            throw new RemoteFailed("{$card->id()}: {$why}, ".self::ROUNDS.' rounds running; it stays queued');
        }
        // the fixes on the commit this round checked (or pushed), which passed the merger gate
        $fixes = isset($state['checked'], $state['merge_commit']) && $state['checked'] !== $state['merge_commit']
            ? ['from' => $state['merge_commit'], 'to' => $state['checked']] : ($state['replay'] ?? null);
        $this->state->set(['phase' => MergeState::MERGING, 'round' => $round, 'merged' => null, 'checked' => null, 'failure' => null,
            'conflicts' => null, 'on_main' => null, 'replay' => $fixes]);
        ($this->say)("{$card->id()}: {$why}; merging again on the new {$this->main} (round {$round})");
    }

    /**
     * An unexpected failure, counted per card and phase in `merge-attempts.json`, which outlives the merge.json a release
     * clears. Outside a push the lease goes back; the third failure in one phase blocks the card.
     */
    private function failed(Card $card, Throwable $e): int
    {
        $message = Worktrees::tail($e->getMessage());
        ($this->fault)("{$card->id()}: merge failed: ".basename(str_replace('\\', '/', $e::class)).": {$message}");
        $state = $this->state->read();
        if ($state === null) {
            return 1;
        }
        $phase = (string) $state['phase'];
        if ($phase === MergeState::PUSHING) {
            return 1;
        }
        $this->strike($card, $phase, $message);
        $this->leave($card->id());

        return 1;
    }

    /** One more failure of the card's merge in $phase; the third blocks the card, with $message. Whether it was the third. */
    private function strike(Card $card, string $phase, string $message): bool
    {
        if ($this->attempt($card->id(), $phase) < self::ATTEMPTS) {
            return false;
        }
        $this->attempt($card->id(), null);
        try {
            $this->store->update($card->id(), fn (array $data) => ['blocked' => mb_substr('merge failed '.self::ATTEMPTS."×: {$message}", 0, 500)] + $data, $this->actor);
        } catch (Throwable $blocked) {
            ($this->fault)("{$card->id()} not blocked: {$blocked->getMessage()}");
        }

        return true;
    }

    /**
     * Whether a merge whose stack failed stays for the next `finish`, its lease held: one that holds its merger's work or
     * waits on its merger, until the third failure in a phase blocks the card.
     */
    private function kept(Card $card, string $message): bool
    {
        $state = $this->state->read();
        if (! $this->mergerWork($state)) {
            return false;
        }
        if ($this->strike($card, (string) $state['phase'], $message)) {
            return false;
        }
        ($this->say)("{$card->id()}: the merge and its lease stay: the next `kanban finish {$card->id()}` goes on with it");

        return true;
    }

    /**
     * Whether the merge ($state, merge.json) holds its merger's work or waits on its merger.
     *
     * @param  array<string, mixed>|null  $state
     */
    private function mergerWork(?array $state): bool
    {
        return $state !== null && ! in_array($state['phase'], [MergeState::RELEASING, MergeState::RELEASED], true)
            && ((int) ($state['merger_rounds'] ?? 0) > 0 || isset($state['replay'])
                || in_array($state['phase'], [MergeState::CONFLICT, MergeState::RED], true));
    }

    /** One more unexpected failure of the card's merge in $phase: the count so far. A null $phase forgets the card's. */
    private function attempt(string $card, ?string $phase): int
    {
        $file = $this->paths->runtime('merge-attempts.json');
        $attempts = Runtime::readJson($file) ?? [];
        if ($phase === null) {
            unset($attempts[$card]);
        } else {
            $attempts[$card][$phase] = ($attempts[$card][$phase] ?? 0) + 1;
        }
        Runtime::writeJson($file, $attempts);

        return $phase === null ? 0 : $attempts[$card][$phase];
    }

    /** The lease given back; a release that does not land now is retried by the next `finish` or `kanban run` pass. */
    private function letGo(): void
    {
        if ($this->state->read() === null) {
            return;
        }
        try {
            $this->lease->release();
        } catch (Throwable $e) {
            ($this->fault)("merge lease not given back yet ({$e->getMessage()}): the next `kanban finish` gives it back");
        }
    }

    /** The merge of $card is over here: the lease given back, the merge clone back at the base, the card's pins gone. */
    private function leave(string $card): void
    {
        $this->letGo();
        $this->reset($card);
    }

    /** Ends the card's merger this machine runs, named; a worker or evaluator of the card launched meanwhile goes on. */
    private function stopAgents(string $card): void
    {
        foreach ((new AgentRun($this->paths, $this->config))->stopCard($card, type: AgentRun::MERGER) as $run) {
            ($this->say)('stopped its '.str_replace('kanban-', '', (string) $run['type']).' '.substr((string) $run['session'], 0, 8));
        }
    }

    /**
     * Without a remote the merge fast-forwards the main checkout: why it cannot now (a change of its own, or a file it
     * does not track where the card at $head adds one), thrown before the lease is taken or a check runs. Each reason
     * starts `main checkout not moved`, which `kanban run` raises for a person.
     */
    private function localMain(string $id, string $head): void
    {
        if ($this->push !== null) {
            return;
        }
        if (($why = $this->worktrees->notOnMain()) !== null) {
            throw new Waiting("main checkout not moved: {$why}; the merge fast-forwards it");
        }
        if (($changed = $this->worktrees->changed($this->paths->main)) !== []) {
            throw new Waiting("main checkout not moved: it has uncommitted changes, which the merge would meet: commit or stash them, then `kanban finish {$id}`", $changed);
        }
        $added = array_filter(explode("\0", $this->git->attempt(['diff', '-z', '--name-only', '--no-renames', '--diff-filter=A', "refs/heads/{$this->main}...{$head}"])->out));
        $present = array_values(array_filter($added, fn (string $f) => file_exists($this->paths->main.'/'.$f) || is_link($this->paths->main.'/'.$f)));
        $untracked = $present === [] ? [] : array_filter(explode("\0", $this->git->attempt(['--literal-pathspecs', 'ls-files', '-z', '--others', '--exclude-standard', '--', ...$present])->out));
        if ($untracked !== []) {
            throw new Waiting("main checkout not moved: the merge of {$id} adds ".implode(', ', array_slice($untracked, 0, 10)).(count($untracked) > 10 ? ' …' : '')
                .', which git does not track here: remove or commit '.(count($untracked) === 1 ? 'it' : 'them').", then `kanban finish {$id}`");
        }
    }

    private function applier(): Applier
    {
        return new Applier($this->store, $this->paths, $this->config, new Runtime($this->paths));
    }

    /** The merge clone back at the card's base, nothing of the merge in its working tree; the card's pins gone. */
    private function reset(string $card): void
    {
        try {
            if ($this->clone->pinned($card, 'base') !== null && $this->clone->exists()) {
                $this->clone->fetchPins($card);
                $this->clone->checkout('refs/merge/base');
            }
        } catch (Throwable) {
            // the stack may be what broke: the next merge resets the clone before it uses it
        }
        $this->clone->unpin($card);
    }

    private function beater(string $lease): void
    {
        (new MergeBeat($this->paths, $this->lease, new AgentRun($this->paths, $this->config)))->ensure($lease);
    }

    private function checkout(): MainCheckout
    {
        return new MainCheckout($this->paths, $this->config, $this->say, function (string $line) {
            $this->faulted = true;
            ($this->fault)($line);
        }, $this->rebuild);
    }

    private function guard(string $kind, Closure $step): bool
    {
        try {
            return (bool) $step();
        } catch (Throwable $e) {
            ($this->fault)("{$kind}: ".basename(str_replace('\\', '/', $e::class)).': '.Worktrees::tail($e->getMessage()));

            return false;
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function log(string $id, array $entry): Card
    {
        return $this->store->update($id, function (array $data) use ($entry) {
            $data['log'][] = $entry;

            return $data;
        }, $this->actor);
    }

    private function transitions(): Transitions
    {
        return new Transitions($this->store);
    }

    /** `finish` with each key the project leaves out at the package default. @return array<string, mixed> */
    private function finish(): array
    {
        $package = require dirname(__DIR__, 3).'/config/kanban.php';

        return (array) ($this->mainConfig()['finish'] ?? []) + $package['finish'];
    }

    /** @return array<string, mixed> main's config, read from the main checkout once it followed main */
    private function mainConfig(): array
    {
        return $this->mainConfig ??= Standalone::config($this->paths->main);
    }

    /**
     * Why the queue merges nothing under $config (main's `kanban` config): finish.check names no suite, or stacks are off.
     * Null when it merges.
     *
     * @param  array<string, mixed>  $config
     */
    public static function refusal(array $config, string $main): ?string
    {
        return match (true) {
            (new Suite($config))->commands() === [] => self::NO_SUITE,
            ! Stack::enabled((array) ($config['stack'] ?? []), $main) => self::NO_STACK,
            default => null,
        };
    }

    private function requireQueue(): void
    {
        if (($why = self::refusal($this->config, $this->paths->main)) !== null) {
            throw new PolicyRefused($why);
        }
    }
}
