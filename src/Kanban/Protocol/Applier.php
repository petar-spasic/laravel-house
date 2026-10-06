<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
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
        $dirty = array_values(array_filter(explode("\n", rtrim($git->attempt(['status', '--porcelain', '--untracked-files=all'])->out))));
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
        if (($markers = (new MergeCheck($git, $main))->markers('refs/heads/'.$main)) !== []) {
            return MergeCheck::markersMessage($markers);
        }
        // the card's own merges: what main brought along was judged when it merged there
        if (($evil = (new MergeCheck($git, $main))->evilMerges("refs/heads/{$main}..HEAD")) !== []) {
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
            if (is_file($this->runtime->appliedFile($id, 'report', $hash))) {
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

            $status = (string) $report['status'];
            $this->store->update($id, function (array $data) use ($report, $status, $head, $created, $known) {
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
                $data['log'] = [...$data['log'], ...self::upstream($report)];

                return $status === 'review' && $data['stage'] === 'doing' ? Transitions::stage($data, 'review', 'apply') : $data;
            }, $by);
            $this->runtime->markApplied($id, 'report', $hash);
            $card = $this->store->card($id);

            return "{$id}: report applied, stage {$card->stage()}".($status === 'blocked' ? ', blocked' : '').self::found($created, $report, $known);
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
            if (is_file($this->runtime->appliedFile($id, 'verdict', $hash))) {
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
                $this->store->update($id, function (array $data) use ($verdict, $hash, $moot, $superseded, $created, $known) {
                    $data['log'] = [...$data['log'], array_filter([
                        'event' => $moot !== null ? 'verdict_moot' : 'verdict_superseded', 'decision' => $verdict['decision'], 'hash' => $hash,
                        'head' => $verdict['head'] ?? null, 'reason' => $moot ?? $superseded, 'discovered' => $created, 'known' => $known,
                    ], fn ($v) => $v !== null && $v !== []), ...self::upstream($verdict)];

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
            $checks = (array) ($verdict['checks'] ?? []);
            $failed = array_map('intval', array_keys(array_filter($checks, fn (array $c) => $c['result'] === 'fail')));
            $entry = array_filter([
                'event' => 'verdict', 'decision' => $verdict['decision'], 'hash' => $hash, 'head' => $verdict['head'] ?? null,
                'failed' => array_map(fn (int $n) => $n.': '.self::cut($checks[(string) $n]['evidence'] ?? '', 300), $failed),
                'issues' => array_map(fn (string $i) => self::cut($i, 300), $verdict['issues'] ?? []),
                'discovered' => $created, 'known' => $known,
                'note' => self::cut($verdict['note'] ?? null, 500),
            ], fn ($v) => $v !== null && $v !== []);

            if ($approve) {
                $this->store->update($id, function (array $data) use ($verdict, $entry) {
                    $data['acceptance'] = self::tick($data['acceptance'] ?? [], array_column($data['acceptance'] ?? [], 'id'), true);
                    $data['work']['approved'] = ['head' => $verdict['head'], 'base' => $verdict['base'] ?? null, 'at' => Clock::now()];
                    $data['log'] = [...$data['log'], $entry, ...self::upstream($verdict)];

                    return $data;
                }, $by);
                $this->runtime->markApplied($id, 'verdict', $hash);

                return "{$id}: approved at ".substr((string) $verdict['head'], 0, 7).self::found($created, $verdict, $known);
            }

            $this->store->update($id, function (array $data) use ($failed, $entry, $verdict) {
                $data['acceptance'] = self::tick($data['acceptance'] ?? [], $failed, false);
                $data['log'] = [...$data['log'], $entry, ...self::upstream($verdict)];

                return $data;
            }, $by);
            if ($card->stage() === 'review') {
                $note = 'rejected'.($failed === [] ? '' : ': criteria '.implode(', ', $failed).' fail')
                    .(($verdict['issues'] ?? []) === [] ? '' : '; '.count($verdict['issues']).' issue(s)');
                (new Transitions($this->store))->sendBack($id, 'reject', $by, $note);
            }
            $this->runtime->markApplied($id, 'verdict', $hash);

            return "{$id}: rejected, stage ".$this->store->card($id)->stage().self::found($created, $verdict, $known);
        });
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

        return ($created === [] ? '' : ', discovered '.implode(', ', $created)).($known === [] ? '' : ', already on the board: '.implode(', ', $known))
            .($upstream === 0 ? '' : ", {$upstream} upstream finding(s) for main");
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
