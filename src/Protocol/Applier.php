<?php

namespace PetarSpasic\Kanban\Protocol;

use Closure;
use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Git\GitStore;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Git;
use PetarSpasic\Kanban\Support\Paths;

/**
 * Applies staged reports and verdicts to the board under the store lock, once per content hash.
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

    /** Why a review report cannot be applied (dirty tree, no commits, failing gate), or null. */
    public function refusal(Card $card, bool $gates = true): ?string
    {
        $worktree = $this->worktree($card);
        if ($worktree === null || ! is_dir($worktree)) {
            return "{$card->id()} has no worktree to check";
        }
        $git = new Git($worktree);
        $dirty = array_values(array_filter(explode("\n", rtrim($git->attempt(['status', '--porcelain', '--untracked-files=all'])->out))));
        if ($dirty !== []) {
            return 'The worktree has uncommitted changes; commit them (git add … && git commit -m "'.$card->id().': …"):'."\n"
                .implode("\n", array_slice($dirty, 0, 20)).(count($dirty) > 20 ? "\n… ".(count($dirty) - 20).' more' : '');
        }
        $base = $card->work()['base'] ?? null;
        $range = (is_string($base) && $base !== '' ? $base : 'refs/heads/'.($this->config['main_branch'] ?? 'main')).'..HEAD';
        if ((int) $git->line(['rev-list', '--count', $range]) === 0) {
            return "No commits beyond work.base ({$range}): commit your work on the card's branch first.";
        }

        return $gates ? (new Gates($this->config))->failure($worktree) : null;
    }

    /**
     * Applies whatever is staged for the card (report or verdict) without an agent to answer: a review report the
     * branch does not support yet stays staged. Returns what happened, one line, or null when nothing is staged.
     */
    public function settle(string $cardId, string $kind): ?string
    {
        $item = $this->runtime->staged($cardId, $kind);
        if ($item === null) {
            return null;
        }
        try {
            if ($kind === 'report' && $item['status'] === 'review' && ($refusal = $this->refusal($this->store->card($cardId))) !== null) {
                return "{$cardId}: report stays staged: ".strtok($refusal, "\n");
            }

            return $kind === 'report' ? $this->report($item) : $this->verdict($item);
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
            if (is_file($this->runtime->appliedFile($id, 'report', $hash))) {
                $this->runtime->markApplied($id, 'report', $hash);

                return "{$id}: report {$hash} already applied";
            }
            $by = new Actor('worker');
            $card = $this->store->card($id);
            $worktree = $this->worktree($card);
            $head = ($worktree !== null && is_dir($worktree) ? (new Git($worktree))->line(['rev-parse', 'HEAD']) : null) ?? $report['head'] ?? null;
            $created = $this->discover($card, $report['discovered'] ?? [], $by, 'working on');

            $status = (string) $report['status'];
            $this->store->update($id, function (array $data) use ($report, $status, $head, $created) {
                $data['acceptance'] = self::tick($data['acceptance'] ?? [], $report['ticks'] ?? [], true);
                if (is_array($data['work'] ?? null)) {
                    $data['work']['head'] = $head;
                }
                $data['blocked'] = $status === 'blocked' ? mb_substr((string) $report['reason'], 0, 500) : null;
                $data['log'][] = array_filter([
                    'event' => 'report', 'status' => $status, 'hash' => $report['hash'], 'head' => $head,
                    'ticks' => $report['ticks'] ?? [], 'summary' => self::cut($report['summary'] ?? null, 2000),
                    'verified' => $report['verified'] ?? [], 'discovered' => $created,
                    'reason' => $report['reason'] ?? null, 'note' => self::cut($report['note'] ?? null, 500),
                ], fn ($v) => $v !== null && $v !== []);

                return $status === 'review' && $data['stage'] === 'doing' ? Transitions::stage($data, 'review', 'apply') : $data;
            }, $by);
            $this->runtime->markApplied($id, 'report', $hash);
            $card = $this->store->card($id);

            return "{$id}: report applied, stage {$card->stage()}".($status === 'blocked' ? ', blocked' : '').self::found($created);
        });
    }

    /**
     * Applies a staged evaluator verdict: approve → work.approved; reject → back to doing, failed criteria unticked.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function verdict(array $verdict): string
    {
        $id = (string) $verdict['card'];
        $hash = (string) $verdict['hash'];

        return $this->locked(function () use ($verdict, $id, $hash) {
            if (is_file($this->runtime->appliedFile($id, 'verdict', $hash))) {
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: verdict {$hash} already applied";
            }
            $by = new Actor('evaluator');
            $card = $this->store->card($id);
            $approve = $verdict['decision'] === 'approve';
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
            $created = $this->discover($card, $verdict['discovered'] ?? [], $by, 'evaluating');
            $checks = (array) ($verdict['checks'] ?? []);
            $failed = array_map('intval', array_keys(array_filter($checks, fn (array $c) => $c['result'] === 'fail')));
            $entry = array_filter([
                'event' => 'verdict', 'decision' => $verdict['decision'], 'hash' => $hash, 'head' => $verdict['head'] ?? null,
                'failed' => array_map(fn (int $n) => $n.': '.self::cut($checks[(string) $n]['evidence'] ?? '', 300), $failed),
                'issues' => array_map(fn (string $i) => self::cut($i, 300), $verdict['issues'] ?? []),
                'discovered' => $created,
                'note' => self::cut($verdict['note'] ?? null, 500),
            ], fn ($v) => $v !== null && $v !== []);

            if ($approve) {
                $this->store->update($id, function (array $data) use ($verdict, $entry) {
                    $data['acceptance'] = self::tick($data['acceptance'] ?? [], array_column($data['acceptance'] ?? [], 'id'), true);
                    $data['work']['approved'] = ['head' => $verdict['head'], 'base' => $verdict['base'] ?? null, 'at' => Clock::now()];
                    $data['log'][] = $entry;

                    return $data;
                }, $by);
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: approved at ".substr((string) $verdict['head'], 0, 7).self::found($created);
            }

            $this->store->update($id, function (array $data) use ($failed, $entry) {
                $data['acceptance'] = self::tick($data['acceptance'] ?? [], $failed, false);
                $data['log'][] = $entry;

                return $data;
            }, $by);
            if ($card->stage() === 'review') {
                $note = 'rejected'.($failed === [] ? '' : ': criteria '.implode(', ', $failed).' fail')
                    .(($verdict['issues'] ?? []) === [] ? '' : '; '.count($verdict['issues']).' issue(s)');
                (new Transitions($this->store))->sendBack($id, 'reject', $by, $note);
            }
            $this->runtime->markApplied($id, 'verdict', $hash);

            return "{$id}: rejected, stage ".$this->store->card($id)->stage().self::found($created);
        });
    }

    /**
     * Files what an agent found outside its card as backlog cards on the card's board.
     *
     * @param  list<array{type: string, title: string, body?: string}>  $items
     * @return list<string> the new card ids
     */
    private function discover(Card $card, array $items, Actor $by, string $while): array
    {
        return array_map(fn (array $found) => $this->store->create($card->board, [
            'type' => $found['type'], 'title' => $found['title'], 'labels' => ['discovered'],
            'body' => trim("Discovered by {$card->id()} ({$card->title()}) while {$while} it.\n\n".($found['body'] ?? '')),
        ], $by)->id(), $items);
    }

    /** @param  list<string>  $created */
    private static function found(array $created): string
    {
        return $created === [] ? '' : ', discovered '.implode(', ', $created);
    }

    public function branchHead(Card $card): ?string
    {
        $branch = $card->work()['branch'] ?? null;

        return is_string($branch) && $branch !== '' ? (new Git($this->paths->main))->line(['rev-parse', '--verify', '-q', 'refs/heads/'.$branch]) : null;
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
