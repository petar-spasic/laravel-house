<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Code\CloneGit;
use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\MainRed;
use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\StaleReport;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\GitStore;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * Applies staged reports, verdicts, plans and merge results to the board under the store lock, once per content hash.
 */
final class Applier
{
    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Store $store,
        private readonly Paths $paths,
        private readonly array $config,
        private readonly Runtime $runtime,
    ) {}

    public function worktree(Card $card): ?string
    {
        $relative = $card->work()['worktree'] ?? null;
        if (! is_string($relative) || $relative === '') {
            return null;
        }
        $path = str_starts_with($relative, '/') ? $relative : $this->paths->main.'/'.$relative;

        return realpath($path) ?: $path;
    }

    /**
     * Why a review report cannot be applied (dirty tree, no commits, a leftover conflict marker), or null. With $staged,
     * also when its record does not show the gates passing at the worktree's HEAD; the gates themselves never run here.
     * A blocked report needs only the clean tree: work left uncommitted would keep main from being merged in later.
     *
     * @param  array<string, mixed>|null  $staged
     */
    public function refusal(Card $card, ?array $staged = null, bool $blocked = false): ?string
    {
        $worktree = $this->worktree($card);
        if ($worktree === null || ! is_dir($worktree)) {
            return "{$card->id()} has no worktree to check";
        }
        $worktrees = new Worktrees($this->paths, $this->config);
        $worktrees->sync($worktree, $card->work()['branch'] ?? null);
        $git = Git::untrusted($worktree);
        $merging = $worktrees->merging($worktree) !== null;
        // blocked on the merge of main it cannot resolve: the merge stays in progress for whoever answers
        if ($blocked && $merging) {
            return null;
        }
        if ($merging) {
            return 'A merge of main is in progress: conclude it (`git add` the resolved files, `git commit --no-edit`), then report again.';
        }
        $status = Git::untrusted($worktree)->attempt(['status', '--porcelain', '--untracked-files=all', '--ignore-submodules=all']);
        if (! $status->ok()) {
            return 'git status failed in the clone: '.Worktrees::tail($status->err ?: $status->out);
        }
        $dirty = array_values(array_filter(explode("\n", rtrim($status->out))));
        if ($dirty !== []) {
            return 'The worktree has uncommitted changes; commit them (git add … && git commit -m "'.$card->id().': …"):'."\n"
                .implode("\n", array_slice($dirty, 0, 20)).(count($dirty) > 20 ? "\n… ".(count($dirty) - 20).' more' : '');
        }
        if ($blocked) {
            return null;
        }
        $main = (string) ($this->config['main_branch'] ?? 'main');
        $base = $card->work()['base'] ?? null;
        $range = (is_string($base) && $base !== '' ? $base : 'refs/heads/'.$main).'..HEAD';
        if ((int) $git->line(['rev-list', '--count', $range]) === 0) {
            return "No commits beyond work.base ({$range}): commit your work on the card's branch first.";
        }
        // its commits are read in main, whose config and attributes are this machine's: the clone's never run here
        $head = (string) $git->line(['rev-parse', 'HEAD']);
        $checks = new MergeCheck(new Git($this->paths->main));
        if (preg_match('/^[0-9a-f]{40,64}$/', $head) !== 1 || ! $checks->has($head)) {
            return 'HEAD '.substr($head, 0, 7).' did not reach main with the card\'s branch: commit on '.($card->work()['branch'] ?? 'the card\'s branch').', then report again.';
        }
        if (($markers = $checks->markers('refs/heads/'.$main, $head)) !== []) {
            return MergeCheck::markersMessage($markers);
        }
        // the card's own merges: what main brought along was judged when it merged there
        if (($evil = $checks->evilMerges("refs/heads/{$main}..{$head}")) !== []) {
            return 'A merge of '.$main.' carries changes neither side had (uncommitted work committed with it?), where no review sees them as the card\'s change:'
                ."\n".implode("\n", $evil)."\n".'Run `vendor/bin/kanban rebuild-branch '.$card->id().'`: one commit on '.$main.' with the same files, so the whole change is reviewed; then report again.';
        }

        if ($staged !== null && ($unproven = (new Gates($this->config))->unproven($staged['gates'] ?? null, (string) $git->line(['rev-parse', 'HEAD']))) !== null) {
            return "Gates not proven: {$unproven}. Run `vendor/bin/kanban report` again: it runs the gates and stages the report.";
        }

        return null;
    }

    /**
     * Why a staged worker report must not be applied to $card now, or null. A pull may have brought another person's
     * re-claim or a move, and an old report must not overwrite either.
     */
    public function stale(Card $card): ?string
    {
        if (! in_array($card->stage(), ['doing', 'review'], true)) {
            return "{$card->id()} is now {$card->stage()}";
        }
        if (($host = $card->host()) !== null && $host !== gethostname()) {
            return "{$card->id()} is now held on {$host}";
        }

        return null;
    }

    /**
     * Why a staged plan must not be applied to $card now, or null: a planner works only on a card it holds in planning,
     * here.
     */
    public function stalePlan(Card $card): ?string
    {
        if ($card->stage() !== 'planning' || ! $card->atWork()) {
            return "{$card->id()} is now {$card->stage()}".($card->stage() === 'planning' ? ', held by no planner' : '');
        }
        if (($host = $card->host()) !== null && $host !== gethostname()) {
            return "{$card->id()} is now planned on {$host}";
        }

        return null;
    }

    /**
     * Applies whatever is staged for the card (report, verdict, plan or merge result) without an agent to answer: a review
     * report the branch does not support yet stays staged. Returns what happened, one line, or null when nothing is staged.
     */
    public function settle(string $cardId, string $kind): ?string
    {
        $item = $this->runtime->staged($cardId, $kind);
        if ($item === null) {
            return null;
        }
        try {
            if ($kind === 'plan') {
                return $this->plan($item);
            }
            if ($kind === 'merge') {
                return $this->merge($item);
            }
            if ($kind === 'report') {
                $card = $this->store->card($cardId);
                if (($stale = $this->stale($card)) !== null) {
                    return "{$cardId}: report stays staged: {$stale}";
                }
                if (($predates = self::predates($card, $item)) !== null) {
                    @unlink($this->runtime->stagedFile($cardId, $kind));

                    return "{$cardId}: report {$predates} and was discarded";
                }
            }
            if ($kind === 'report' && $item['status'] === 'review' && ($refusal = $this->refusal($this->store->card($cardId), $item)) !== null) {
                $this->runtime->noteRefusal($cardId, $kind, $refusal);

                return "{$cardId}: report stays staged: ".strtok($refusal, "\n");
            }

            return $kind === 'report' ? $this->report($item) : $this->verdict($item, false);
        } catch (KanbanException $e) {
            return "{$cardId}: {$kind} not applied: ".$e->getMessage();
        }
    }

    /**
     * Applies a staged worker report. Returns what happened, one line.
     *
     * @param  array<string, mixed>  $report
     */
    public function report(array $report): string
    {
        $id = (string) $report['card'];
        $hash = (string) $report['hash'];

        return $this->locked(function () use ($report, $id, $hash) {
            if (is_file($this->runtime->appliedFile($id, 'report', $hash)) || self::landed($this->store->card($id), ['report'], 'hash', $hash)) {
                $this->runtime->markApplied($id, 'report', $hash);

                return "{$id}: report {$hash} already applied";
            }
            $by = new Actor('worker');
            $card = $this->store->card($id);
            if (($stale = $this->stale($card)) !== null) {
                throw new StaleReport($stale);
            }
            if (($predates = self::predates($card, $report)) !== null) {
                @unlink($this->runtime->stagedFile($id, 'report'));

                return "{$id}: report {$predates} and was discarded";
            }
            $worktree = $this->worktree($card);
            $head = ($worktree !== null && is_dir($worktree) ? Git::untrusted($worktree)->line(['rev-parse', 'HEAD']) : null) ?? $report['head'] ?? null;
            $created = $this->discover($card, $report['discovered'] ?? [], $by, 'working on', $known);
            $aside = $this->aside($card, $report);

            $status = (string) $report['status'];
            $this->store->update($id, function (array $data) use ($report, $status, $head, $created, $known, $aside) {
                $data['acceptance'] = self::tick($data['acceptance'] ?? [], $report['ticks'] ?? [], true);
                if (is_array($data['work'] ?? null)) {
                    $data['work']['head'] = $head;
                }
                $data['blocked'] = $status === 'blocked' ? mb_substr((string) $report['reason'], 0, 500) : null;
                if (($report['questions'] ?? []) !== []) {
                    $data['body'] = Questions::append((string) ($data['body'] ?? ''), $report['questions']);
                }
                // a report on a card in review is a new round: whatever an evaluator approved is no longer what it reports
                if ($data['stage'] === 'review') {
                    $data['work']['approved'] = null;
                }
                $data['log'][] = array_filter([
                    'event' => 'report', 'status' => $status, 'hash' => $report['hash'], 'head' => $head,
                    'ticks' => $report['ticks'] ?? [], 'summary' => self::cut($report['summary'] ?? null, Staged::SUMMARY),
                    'verified' => $report['verified'] ?? [], 'discovered' => $created, 'known' => $known,
                    'reason' => $report['reason'] ?? null, 'note' => self::cut($report['note'] ?? null, 500),
                ], fn ($v) => $v !== null && $v !== []);
                $data['log'] = [...$data['log'], ...$aside];

                return $status === 'review' && $data['stage'] === 'doing' ? Transitions::stage($data, 'review', 'apply') : $data;
            }, $by);
            $this->runtime->markApplied($id, 'report', $hash);
            $card = $this->store->card($id);

            return "{$id}: report applied, stage {$card->stage()}".($status === 'blocked' ? ', blocked' : '').self::found($created, $report, $known);
        });
    }

    /**
     * Applies a staged plan: a ready one puts the plan and its `planned` entry on the card (the card stays in planning, held,
     * until StopCommand releases it to ready), a blocked one the block; questions join the body, discovered items become
     * cards. A plan for a card whose content changed since it was staged is refused: its planner revises it.
     *
     * @param  array<string, mixed>  $staged
     */
    public function plan(array $staged): string
    {
        $id = (string) $staged['card'];
        $hash = (string) $staged['hash'];

        return $this->locked(function () use ($staged, $id, $hash) {
            if (is_file($this->runtime->appliedFile($id, 'plan', $hash)) || self::landed($this->store->card($id), ['planned', 'plan'], 'staged', $hash)) {
                $this->runtime->markApplied($id, 'plan', $hash);

                return "{$id}: plan {$hash} already applied";
            }
            $by = new Actor('planner');
            $card = $this->store->card($id);
            if (($stale = $this->stalePlan($card)) !== null) {
                throw new StaleReport($stale);
            }
            if ((string) ($staged['staged_at'] ?? '') < (string) ($card->claim()['at'] ?? '')) {
                @unlink($this->runtime->stagedFile($id, 'plan'));

                return "{$id}: plan predates its planner's claim of the card and was discarded";
            }
            $status = (string) $staged['status'];
            if ($status === 'ready' && ($staged['content'] ?? null) !== Plan::hash($card->data)) {
                throw new PolicyRefused("{$id} changed since the plan was staged (its criteria or body): run `vendor/bin/kanban context`, revise the plan to cover the card as it is, and stage it again");
            }
            $created = $this->discover($card, $staged['discovered'] ?? [], $by, 'planning', $known);
            $aside = $this->aside($card, $staged);
            $this->store->update($id, function (array $data) use ($staged, $status, $created, $known, $hash, $aside) {
                if (($staged['questions'] ?? []) !== []) {
                    $data['body'] = Questions::append((string) ($data['body'] ?? ''), $staged['questions']);
                }
                $data['blocked'] = $status === 'blocked' ? mb_substr((string) $staged['reason'], 0, 500) : null;
                if ($status === 'ready') {
                    $data['plan'] = (string) $staged['plan'];
                }
                $data['log'][] = array_filter($status === 'ready'
                    ? ['event' => 'planned', 'base' => $staged['base'] ?? null, 'hash' => Plan::hash($data), 'staged' => $hash, 'head' => $staged['head'] ?? null,
                        'discovered' => $created, 'known' => $known, 'note' => self::cut($staged['note'] ?? null, 500)]
                    : ['event' => 'plan', 'status' => 'blocked', 'staged' => $hash, 'reason' => $staged['reason'] ?? null, 'discovered' => $created, 'known' => $known,
                        'note' => self::cut($staged['note'] ?? null, 500)], fn ($v) => $v !== null && $v !== []);
                $data['log'] = [...$data['log'], ...$aside];

                return $data;
            }, $by);
            $this->runtime->markApplied($id, 'plan', $hash);

            return "{$id}: ".($status === 'ready' ? 'plan applied ('.count(explode("\n", (string) $staged['plan'])).' lines)' : 'planning blocked').self::found($created, $staged, $known);
        });
    }

    /**
     * Applies a staged evaluator verdict: approve → work.approved; reject → back to doing, failed criteria unticked. A
     * verdict on an earlier round of the card is superseded, and one on a card that left doing and review (finished on an
     * earlier approval, stopped) is moot: logged, its discovered items filed, the card left alone.
     * $answerable: its evaluator is still there to re-verify an approval of a head the branch has left.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function verdict(array $verdict, bool $answerable = true): string
    {
        $id = (string) $verdict['card'];
        $hash = (string) $verdict['hash'];

        return $this->locked(function () use ($verdict, $id, $hash, $answerable) {
            if (is_file($this->runtime->appliedFile($id, 'verdict', $hash)) || self::landed($this->store->card($id), ['verdict', 'verdict_moot', 'verdict_superseded'], 'hash', $hash)) {
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: verdict {$hash} already applied";
            }
            $by = new Actor('evaluator');
            $card = $this->store->card($id);
            $approve = $verdict['decision'] === 'approve';
            $moot = in_array($card->stage(), ['doing', 'review'], true) ? null : "the card is {$card->stage()}";
            $superseded = null;
            if ($moot !== null || ($superseded = $this->superseded($card, $verdict, $approve && $answerable)) !== null) {
                $created = $this->discover($card, $verdict['discovered'] ?? [], $by, 'evaluating', $known);
                $aside = $this->aside($card, $verdict);
                $this->store->update($id, function (array $data) use ($verdict, $hash, $moot, $superseded, $created, $known, $aside) {
                    $data['log'] = [...$data['log'], array_filter([
                        'event' => $moot !== null ? 'verdict_moot' : 'verdict_superseded', 'decision' => $verdict['decision'], 'hash' => $hash,
                        'head' => $verdict['head'] ?? null, 'reason' => $moot ?? $superseded, 'discovered' => $created, 'known' => $known,
                    ], fn ($v) => $v !== null && $v !== []), ...$aside];

                    return $data;
                }, $by);
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: verdict ".($moot !== null ? "moot: {$moot}" : "superseded: {$superseded}").self::found($created, $verdict, $known);
            }
            if ($approve) {
                if ($card->stage() !== 'review') {
                    throw new PolicyRefused("{$id} is {$card->stage()}, not review: the approval does not apply");
                }
                $branchHead = $this->branchHead($card);
                if ($branchHead !== ($verdict['head'] ?? null)) {
                    throw new PolicyRefused('the verdict is for '.substr((string) ($verdict['head'] ?? '-'), 0, 7).' but the branch is at '
                        .substr((string) $branchHead, 0, 7).': re-verify the current HEAD and run `vendor/bin/kanban verdict` again');
                }
            }
            $created = $this->discover($card, $verdict['discovered'] ?? [], $by, 'evaluating', $known);
            $aside = $this->aside($card, $verdict);
            $checks = (array) ($verdict['checks'] ?? []);
            $failed = array_map('intval', array_keys(array_filter($checks, fn (array $c) => $c['result'] === 'fail')));
            $entry = array_filter([
                'event' => 'verdict', 'decision' => $verdict['decision'], 'hash' => $hash, 'head' => $verdict['head'] ?? null,
                'failed' => array_map(fn (int $n) => $n.': '.($checks[(string) $n]['evidence'] ?? ''), $failed),
                'issues' => array_values($verdict['issues'] ?? []),
                'discovered' => $created, 'known' => $known,
                'note' => self::cut($verdict['note'] ?? null, 500),
            ], fn ($v) => $v !== null && $v !== []);

            if ($approve) {
                $this->store->update($id, function (array $data) use ($verdict, $entry, $aside) {
                    $data['acceptance'] = self::tick($data['acceptance'] ?? [], array_column($data['acceptance'] ?? [], 'id'), true);
                    $data['work']['approved'] = ['head' => $verdict['head'], 'at' => Clock::now()];
                    $data['log'] = [...$data['log'], $entry, ...$aside];

                    return $data;
                }, $by);
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: approved at ".substr((string) $verdict['head'], 0, 7).self::found($created, $verdict, $known);
            }

            // the verdict and the send-back: one write, so a failure leaves neither and the verdict is applied again whole
            $note = 'rejected'.($failed === [] ? '' : ': criteria '.implode(', ', $failed).' fail')
                .(($verdict['issues'] ?? []) === [] ? '' : '; '.count($verdict['issues']).' issue(s)');
            $card = $this->store->update($id, function (array $data) use ($failed, $entry, $note, $aside) {
                $before = $data;
                $data['acceptance'] = self::tick($data['acceptance'] ?? [], $failed, false);
                $data['log'] = [...$data['log'], $entry, ...$aside];

                return $data['stage'] === 'review' ? Transitions::sentBack(Transitions::noted($before, $data), 'reject', $note) : $data;
            }, $by);
            $this->runtime->markApplied($id, 'verdict', $hash);

            return "{$id}: rejected, stage {$card->stage()}".self::found($created, $verdict, $known);
        });
    }

    /**
     * Applies a merger's staged result to the merge it answers (Staged::merge): resolved and fixed are checked again on
     * the merge clone as it is now (mergerHead()), logged, and the merge goes back to its checks; back sends the card to
     * its worker in the same write as its log entry; main records that main is red, which holds the queue (MainRed). back
     * and main leave the merge
     * `released`, for `finish` to give the lease back. A result for a merge that is over is logged as moot. The board
     * write comes first: applied again after a failure, it settles the merge state alone.
     *
     * @param  array<string, mixed>  $item
     */
    public function merge(array $item): string
    {
        $id = (string) $item['card'];
        $hash = (string) $item['hash'];
        $outcome = (string) $item['outcome'];

        return $this->locked(function () use ($item, $id, $hash, $outcome) {
            $states = new MergeState($this->paths);
            $state = $states->read();
            $card = $this->store->card($id);
            $ours = $state !== null && ($state['card'] ?? null) === $id && ($state['lease'] ?? null) === $item['lease'] && ($state['round'] ?? null) === $item['round']
                && in_array($state['phase'] ?? null, [MergeState::CONFLICT, MergeState::RED], true);
            if (is_file($this->runtime->appliedFile($id, 'merge', $hash)) || self::landed($card, ['merge', 'merge_moot'], 'hash', $hash)) {
                $ours && $this->settled($states, $item, $state);
                $this->runtime->markApplied($id, 'merge', $hash);

                return "{$id}: merge result {$hash} already applied";
            }
            $by = new Actor('merger');
            $moot = match (true) {
                $card->stage() !== 'review' => "the card is {$card->stage()}",
                ($card->work()['approved']['head'] ?? null) !== $item['card_head'] => 'its approval changed',
                ($state['card'] ?? null) !== $id => 'no merge of it runs here',
                ! $ours => 'the merge it answers is over',
                default => null,
            };
            if ($moot !== null) {
                $this->store->update($id, function (array $data) use ($hash, $moot) {
                    $data['log'][] = MergeState::moot($hash, $moot);

                    return $data;
                }, $by);
                $this->runtime->markApplied($id, 'merge', $hash);

                return "{$id}: merge result {$outcome} moot: {$moot}";
            }
            $note = (string) $item['note'];
            if (in_array($outcome, ['resolved', 'fixed'], true)) {
                $head = $this->mergerHead($card, $state, $outcome);
                if ($head !== $item['head']) {
                    throw new PolicyRefused('the merge clone moved since the result was staged ('.substr((string) $item['head'], 0, 7).' → '.substr((string) $head, 0, 7).'): run `vendor/bin/kanban merged` again');
                }
                $entry = MergeState::entry($outcome, ['head' => $head, 'hash' => $hash, 'note' => $note]);
                $this->store->update($id, function (array $data) use ($entry) {
                    $data['log'][] = $entry;

                    return $data;
                }, $by);
            } elseif ($outcome === 'back') {
                $what = ($state['conflicts'] ?? []) !== [] ? ['files' => array_values((array) $state['conflicts'])] : ['command' => (string) ($state['failure']['command'] ?? '')];
                $this->store->update($id, function (array $data) use ($note, $hash, $what) {
                    $data['log'][] = MergeState::entry('back', ['note' => $note, 'hash' => $hash] + $what);

                    return Transitions::sentBack($data, 'merge', $note);
                }, $by);
            } else {
                $this->mergerHead($card, $state, $outcome);
                $this->store->update($id, function (array $data) use ($state, $hash, $note) {
                    $data['log'][] = MergeState::entry('main', ['command' => (string) ($state['failure']['command'] ?? ''), 'base' => (string) $state['base'], 'hash' => $hash, 'note' => $note]);

                    return $data;
                }, $by);
            }
            $this->settled($states, $item, $state);
            $this->runtime->markApplied($id, 'merge', $hash);

            return "{$id}: merge result {$outcome} applied";
        });
    }

    /**
     * Why the merge of $card takes no merger result now (none runs here, or it is past the merger's turn), or null.
     */
    public function staleMerge(Card $card): ?string
    {
        $state = (new MergeState($this->paths))->read();

        return match (true) {
            ($state['card'] ?? null) !== $card->id() => 'no merge of it runs here',
            ! in_array($state['phase'] ?? null, [MergeState::CONFLICT, MergeState::RED], true) => "the merge is {$state['phase']}",
            default => null,
        };
    }

    /**
     * The merge clone's HEAD that the merger's resolved or fixed stands on, fetched into main as
     * `refs/merge-queue/<id>/staged`; null for back and main. PolicyRefused when the merge in flight ($state) does not take
     * $outcome now: resolved needs a conflict concluded in one merge commit of the round's two pins, fixed commits on
     * top of the tree the check failed on, main a failure mainRefusal() allows; and what the merger changed must pass the
     * merger gate.
     *
     * @param  array<string, mixed>  $state
     */
    public function mergerHead(Card $card, array $state, string $outcome): ?string
    {
        $id = $card->id();
        $phase = (string) ($state['phase'] ?? '');
        $failure = (array) ($state['failure'] ?? []);
        if (! in_array($phase, [MergeState::CONFLICT, MergeState::RED], true)) {
            throw new PolicyRefused("the merge of {$id} is {$phase}: it takes no merger result now");
        }
        $wants = ['resolved' => MergeState::CONFLICT, 'fixed' => MergeState::RED, 'main' => MergeState::RED][$outcome] ?? $phase;
        if ($phase !== $wants) {
            throw new PolicyRefused("{$outcome} answers ".($wants === MergeState::CONFLICT ? 'a conflict' : 'a failed check')."; the merge of {$id} is {$phase}");
        }
        if ($outcome === 'main' && ($why = self::mainRefusal($failure)) !== null) {
            throw new PolicyRefused($why);
        }
        if (! in_array($outcome, ['resolved', 'fixed'], true)) {
            return null;
        }
        $clone = $this->paths->mergeClone();
        $git = new CloneGit($this->paths, $this->config);
        if ($git->inContainer($clone, ['rev-parse', '-q', '--verify', 'MERGE_HEAD'])->ok() || trim($git->inContainer($clone, ['ls-files', '-u'])->out) !== '') {
            throw new PolicyRefused('the merge is not concluded: resolve each conflict, `git add` the files, `git commit --no-edit`');
        }
        if (($dirty = trim($git->inContainer($clone, ['status', '--porcelain', '--untracked-files=all'])->out)) !== '') {
            throw new PolicyRefused("the merge clone has changes no commit holds; commit them (`git add` a new file first) or remove them:\n{$dirty}");
        }
        $main = new Git($this->paths->main);
        $staged = "refs/merge-queue/{$id}/staged";
        // the upload-pack serving it runs in the clone, with the clone's config: untrusted
        $fetch = Git::untrusted($this->paths->main)->attempt(['fetch', '-q', '--no-tags', $clone, "+HEAD:{$staged}"]);
        $head = $fetch->ok() ? $main->line(['rev-parse', '--verify', '-q', $staged]) : null;
        if ($head === null) {
            throw new PolicyRefused('cannot read the merge clone\'s HEAD: '.Worktrees::tail($fetch->err ?: $fetch->out));
        }
        [$base, $pinned] = [(string) $state['base'], (string) $state['head']];
        if ($outcome === 'resolved' && array_slice(explode(' ', (string) $main->line(['rev-list', '--parents', '-n', '1', $head])), 1) !== [$base, $pinned]) {
            throw new PolicyRefused('HEAD '.substr($head, 0, 7).' is not the merge of main '.substr($base, 0, 7).' and the card '.substr($pinned, 0, 7)
                .': conclude the merge with `git commit --no-edit`, and commit nothing after it');
        }
        $checked = (string) ($state['checked'] ?? '');
        if ($outcome === 'fixed' && ! self::fixes($main, $checked, $head)) {
            throw new PolicyRefused('fixed needs your commits on top of '.substr($checked, 0, 7).', the tree the check failed on, each made on `merge` itself: no merge commit');
        }
        if (($why = $this->mergerGate($base, $pinned, $head)) !== []) {
            throw new PolicyRefused('your changes to the merge '.implode('; ', $why).': change that back in a commit of yours, then run `vendor/bin/kanban merged` again');
        }

        return $head;
    }

    /**
     * Why the merger may not answer `main` to the failed check $failure, or null: it passed on main alone.
     *
     * @param  array<string, mixed>  $failure
     */
    public static function mainRefusal(array $failure): ?string
    {
        return ($failure['base_rerun'] ?? null) === 'passed'
            ? "`{$failure['command']}` passed on main alone: its failure is the merge's (fixed) or the card's (back), not main's"
            : null;
    }

    /** How many commits $from..$head holds when they are fixes on top of $from, one parent each (what a push of main takes); else null. */
    public static function fixes(Git $main, string $from, string $head): ?int
    {
        if (! $main->attempt(['merge-base', '--is-ancestor', $from, $head])->ok() || $main->line(['rev-list', '--min-parents=2', "{$from}..{$head}"]) !== '') {
            return null;
        }

        return (int) $main->line(['rev-list', '--count', "{$from}..{$head}"]);
    }

    /**
     * What the merger changed up to $head, against git's own merge of $base and $card (conflicts left as markers), that
     * no merger may: a change to config/kanban.php or .claude/, a deleted test (a moved one too), an added line that skips a test. Read in
     * main from objects only.
     *
     * @return list<string>
     */
    public function mergerGate(string $base, string $card, string $head): array
    {
        $main = new Git($this->paths->main);
        // -z: git quotes a path it would print with a quote, a backslash or a byte outside ASCII
        $merged = $main->attempt(['--attr-source='.$base, 'merge-tree', '--write-tree', '--name-only', '--no-messages', '-z', $base, $card]);
        $tree = $merged->code <= 1 ? strtok($merged->out, "\0") : false;
        if ($tree === false) {
            return ['cannot be read: git merge-tree failed: '.Worktrees::tail($merged->err)];
        }
        $diff = fn (array $args) => $main->run(['diff', '--no-ext-diff', '--no-textconv', ...$args, $tree, $head, '--']);
        $why = [];
        $listed = explode("\0", rtrim($diff(['--name-status', '--no-renames', '-z']), "\0"));
        for ($i = 0; $i + 1 < count($listed); $i += 2) {
            [$status, $path] = [$listed[$i], $listed[$i + 1]];
            if ($path === 'config/kanban.php' || str_starts_with($path, '.claude/')) {
                $why[$path] = "change {$path}, which no agent may edit";
            }
            if ($status === 'D' && preg_match('#(^|/)tests/#', $path) === 1) {
                $why[$path] = "delete the test {$path}";
            }
        }
        $file = '';
        foreach (explode("\n", $diff(['-U0'])) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $file = substr($line, 6);
            } elseif (str_starts_with($line, '+') && preg_match(Context::SKIPPED, $line) === 1) {
                $why["skip {$file}"] = "add a skipped test in {$file}";
            }
        }

        return array_values($why);
    }

    /**
     * The merge state after the merger's result $item: back to its checks (resolved, fixed), or released (back, main);
     * the merger's launches counted afresh.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $state
     */
    private function settled(MergeState $states, array $item, array $state): void
    {
        $fields = match ($item['outcome']) {
            'resolved' => ['phase' => MergeState::CHECKS, 'merge_commit' => $item['head'], 'conflicts' => null],
            'fixed' => ['phase' => MergeState::CHECKS, 'failure' => null],
            default => ['phase' => MergeState::RELEASED],
        };
        $rounds = $fields['phase'] === MergeState::CHECKS ? ['merger_rounds' => (int) ($state['merger_rounds'] ?? 0) + 1] : [];
        $states->set($fields + $rounds + ['merger_runs' => 0], (string) $item['lease']);
    }

    /**
     * Whether the card's latest entry of $events records the staged item $hash in $field: its write landed, and only
     * marking it applied did not.
     *
     * @param  list<string>  $events
     */
    private static function landed(Card $card, array $events, string $field, string $hash): bool
    {
        foreach (array_reverse($card->log()) as $entry) {
            if (in_array($entry['event'] ?? null, $events, true)) {
                return ($entry[$field] ?? null) === $hash;
            }
        }

        return false;
    }

    /**
     * Why a verdict no longer speaks for the card, or null: its evaluator was bound before the card's current round began
     * (a refresh, a return to doing, a later report), or the branch left the head it judged. An approval whose evaluator
     * can still re-verify is refused instead (verdict()).
     *
     * @param  array<string, mixed>  $verdict
     */
    private function superseded(Card $card, array $verdict, bool $refuseMoved): ?string
    {
        $round = self::roundStart($card, 'verdict');
        $since = (string) ($verdict['since'] ?? $verdict['staged_at'] ?? '');
        if ($round !== null && $since !== '' && $since < $round['at']) {
            return "{$round['what']} at ".substr($round['at'], 0, 16).' came after its evaluator started';
        }
        $head = $this->branchHead($card);
        if (! $refuseMoved && in_array($card->stage(), ['doing', 'review'], true) && $head !== null && $head !== ($verdict['head'] ?? null)) {
            return 'it judged '.substr((string) ($verdict['head'] ?? '-'), 0, 7).' but the branch is at '.substr($head, 0, 7);
        }

        return null;
    }

    /**
     * The latest log entry that began the card's current round: a refresh, a return to doing, and for a verdict also a
     * report.
     *
     * @return array{at: string, what: string}|null
     */
    private static function roundStart(Card $card, string $kind): ?array
    {
        foreach (array_reverse($card->log()) as $entry) {
            $event = $entry['event'] ?? null;
            $what = match (true) {
                $event === 'refresh' => 'a refresh',
                $event === 'stage' && ($entry['to'] ?? null) === 'doing' => 'a return to doing',
                $event === 'report' && $kind === 'verdict' => 'a report',
                default => null,
            };
            if ($what !== null) {
                return ['at' => (string) ($entry['at'] ?? ''), 'what' => $what];
            }
        }

        return null;
    }

    /**
     * Why a staged report belongs to an earlier attempt or round of the card, or null.
     *
     * @param  array<string, mixed>  $report
     */
    private static function predates(Card $card, array $report): ?string
    {
        $at = (string) ($report['staged_at'] ?? '');
        if ($at === '') {
            return null;
        }
        if (($started = (string) ($card->work()['started'] ?? '')) !== '' && $at < $started) {
            return 'predates the last start of the card';
        }
        $round = self::roundStart($card, 'report');

        return $round !== null && $at < $round['at'] ? "predates {$round['what']} of the card" : null;
    }

    /**
     * Files what an agent found outside its card as backlog cards on the card's board, once: an item whose title matches
     * (case, punctuation and spacing aside) an open card, or a card this card filed before, is not filed again.
     *
     * @param  list<array{type: string, title: string, body?: string}>  $items
     * @param  list<string>|null  $known  set to the ids of the cards the skipped items matched
     * @return list<string> the new card ids
     */
    private function discover(Card $card, array $items, Actor $by, string $while, ?array &$known = null): array
    {
        $known = [];
        if ($items === []) {
            return [];
        }
        $snapshot = $this->store->snapshot();
        $titles = [];
        foreach ($snapshot->cards(fn (Card $c) => ! in_array($c->stage(), ['done', 'dropped'], true)) as $open) {
            $titles[self::normal($open->title())] ??= $open->id();
        }
        foreach (self::discoveredBy($card) as $id) {
            if (($earlier = $snapshot->card($id)) !== null) {
                $titles[self::normal($earlier->title())] ??= $id;
            }
        }
        $created = [];
        foreach ($items as $found) {
            // a failure on main is no card: it goes beside the card's own entry (aside())
            if (! empty($found['main'])) {
                continue;
            }
            $title = self::normal($found['title']);
            if (isset($titles[$title])) {
                if (! in_array($titles[$title], $created, true)) {
                    $known[] = $titles[$title];
                }

                continue;
            }
            // its area is the filing card's, so promote takes it once the owner writes its criteria
            $created[] = $titles[$title] = $this->store->create($card->board, [
                'type' => $found['type'], 'title' => $found['title'], 'labels' => ['discovered', ...$card->areas()],
                'body' => trim("Discovered by {$card->id()} ({$card->title()}) while {$while} it.\n\n".($found['body'] ?? '')),
            ], $by)->id();
        }
        $known = array_values(array_unique($known));

        return $created;
    }

    /**
     * The cards the agents of $card filed or matched, oldest first.
     *
     * @return list<string>
     */
    public static function discoveredBy(Card $card): array
    {
        $ids = [];
        foreach ($card->log() as $entry) {
            array_push($ids, ...(array) ($entry['discovered'] ?? []), ...(array) ($entry['known'] ?? []));
        }

        return array_values(array_unique(array_filter($ids, 'is_string')));
    }

    private static function normal(string $title): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }

    /**
     * @param  list<string>  $created
     * @param  array<string, mixed>  $staged
     * @param  list<string>  $known
     */
    private static function found(array $created, array $staged, array $known = []): string
    {
        $upstream = count($staged['upstream'] ?? []);
        $main = count(array_filter($staged['discovered'] ?? [], fn (array $f) => ! empty($f['main'])));

        return ($created === [] ? '' : ', discovered '.implode(', ', $created)).($known === [] ? '' : ', already on the board: '.implode(', ', $known))
            .($upstream === 0 ? '' : ", {$upstream} upstream finding(s) for main").($main === 0 ? '' : ", {$main} failure(s) on main for the main session");
    }

    /**
     * The staged findings about the house package as `upstream` log entries: `kanban upstream` lists and files them.
     *
     * @param  array<string, mixed>  $staged
     * @return list<array<string, string>>
     */
    private static function upstream(array $staged): array
    {
        return array_map(fn (array $f) => array_filter(['event' => 'upstream', 'title' => (string) $f['title'], 'body' => (string) ($f['body'] ?? '')],
            fn (string $v) => $v !== ''), $staged['upstream'] ?? []);
    }

    /**
     * The staged findings that go beside the card's own entry: `upstream` (kanban upstream) and `main_red`, a failure on
     * main for the main session (MainRed), once for each command and main's tip as this machine knows it. A clone that
     * does not hold that tip tested an older main, which may be fixed since: its failures on main are dropped.
     *
     * @param  array<string, mixed>  $staged
     * @return list<array<string, string>>
     */
    private function aside(Card $card, array $staged): array
    {
        $entries = self::upstream($staged);
        $main = array_filter($staged['discovered'] ?? [], fn (array $f) => ! empty($f['main']));
        if ($main === [] || ($base = MainPush::of($this->paths, $this->config)->known()) === null) {
            return $entries;
        }
        $worktree = $this->worktree($card);
        if ($worktree !== null && is_dir($worktree) && ! Git::untrusted($worktree)->attempt(['merge-base', '--is-ancestor', $base, 'HEAD'])->ok()) {
            return $entries;
        }
        $open = array_column(MainRed::open($this->store->snapshot(), $base), 'key');
        foreach ($main as $found) {
            if (in_array($key = "{$base}:{$found['title']}", $open, true)) {
                continue;
            }
            $open[] = $key;
            $entries[] = array_filter(['event' => 'main_red', 'command' => (string) $found['title'], 'base' => $base,
                'body' => mb_strimwidth((string) ($found['body'] ?? ''), 0, 500, '…')], fn (string $v) => $v !== '');
        }

        return $entries;
    }

    /** The branch's head where the work happens: the card's worktree while it exists, else main. */
    public function branchHead(Card $card): ?string
    {
        $branch = $card->work()['branch'] ?? null;
        $worktree = $this->worktree($card);

        return is_string($branch) && $branch !== ''
            ? ($worktree !== null && is_dir($worktree) ? Git::untrusted($worktree) : new Git($this->paths->main))->line(['rev-parse', '--verify', '-q', 'refs/heads/'.$branch])
            : null;
    }

    private function locked(Closure $fn): string
    {
        return $this->store instanceof GitStore ? $this->store->write($fn) : $fn();
    }

    /**
     * @param  list<array<string, mixed>>  $acceptance
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private static function tick(array $acceptance, array $ids, bool $done): array
    {
        return array_map(fn (array $c) => in_array($c['id'], $ids, true) ? ['done' => $done] + $c : $c, $acceptance);
    }

    private static function cut(?string $text, int $max): ?string
    {
        return $text === null ? null : (mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text);
    }
}
