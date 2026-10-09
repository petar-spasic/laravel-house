<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\CardType;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;

/** Builds and validates the staged report (worker), verdict (evaluator), plan (planner) and merge result (merger) of a card. */
final class Staged
{
    /** Characters of a report's summary the card log keeps. */
    public const SUMMARY = 2000;

    /** Characters of a verdict's evidence or issue: kept whole, so the worker reads all of it. */
    public const VERDICT_TEXT = 2000;

    /**
     * @param  list<string>  $ticks
     * @param  list<string>  $verified
     * @param  list<string>  $discovered  `type: Title — body`
     * @param  array{head: string, worktree: string, session: ?string, after: ?string}  $at  after: the card's last report entry
     * @param  list<array{title: string, body: string}>  $upstream  scrubbed findings about the house package (Upstream\Findings::stage)
     * @param  list<string>  $questions  question sections for the card's body (Policy\Questions::file)
     * @return array<string, mixed>
     */
    public static function report(Card $card, string $status, array $ticks, ?string $summary, array $verified, array $discovered,
        ?string $reason, ?string $note, array $at, array $upstream = [], array $questions = []): array
    {
        if (! in_array($status, ['review', 'blocked'], true)) {
            throw new Invalid("--status must be review or blocked, not '{$status}'");
        }
        $summary = self::text($summary);
        $reason = self::text($reason);
        if ($status === 'review' && $summary === null) {
            throw new Invalid('a review report needs --summary (or --summary-file=-)');
        }
        if ($status === 'blocked' && $reason === null) {
            throw new Invalid('a blocked report needs --reason (the question or what is missing)');
        }
        if ($reason !== null && mb_strlen($reason) > 480) {
            throw new Invalid('--reason must be at most 480 characters');
        }

        $item = [
            'card' => $card->id(),
            'status' => $status,
            'ticks' => self::ticks($card, $ticks),
            'summary' => $summary,
            'verified' => array_values(array_filter(array_map('trim', $verified), fn (string $v) => $v !== '')),
            'discovered' => array_map(fn (string $d) => self::discovered($d), $discovered),
            'upstream' => $upstream,
            'reason' => $reason,
            'note' => self::text($note),
        ] + ($questions === [] ? [] : ['questions' => $questions]) + $at;

        return self::seal($item);
    }

    /**
     * @param  list<string>  $checks  `N:pass|fail:evidence`
     * @param  list<string>  $issues
     * @param  list<string>  $discovered  `type: Title — body`; outside the card, so they never decide the verdict
     * @param  array{head: string, worktree: string, session: ?string, since: ?string, after: ?string}  $at  since: when its evaluator started or resumed; after: the card's last verdict entry
     * @param  list<array{title: string, body: string}>  $upstream
     * @return array<string, mixed>
     */
    public static function verdict(Card $card, string $decision, array $checks, array $issues, array $discovered, ?string $note, array $at, array $upstream = []): array
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new Invalid("the verdict is approve or reject, not '{$decision}'");
        }
        $criteria = array_column($card->acceptance(), 'id');
        $parsed = [];
        foreach ($checks as $check) {
            if (preg_match('/^\s*(\d+)\s*:\s*(pass|fail)\s*:\s*(.+)$/s', $check, $m) !== 1) {
                throw new Invalid("--check '{$check}': use N:pass|fail:\"evidence\"");
            }
            $n = (int) $m[1];
            if (! in_array($n, $criteria, true)) {
                throw new Invalid("--check {$n}: {$card->id()} has no criterion {$n} (criteria: ".implode(', ', $criteria).')');
            }
            if (isset($parsed[$n])) {
                throw new Invalid("--check {$n} given twice");
            }
            $parsed[$n] = ['result' => $m[2], 'evidence' => trim($m[3])];
            if (($length = mb_strlen($parsed[$n]['evidence'])) > self::VERDICT_TEXT) {
                throw new Invalid("--check {$n}: the evidence is {$length} characters, at most ".self::VERDICT_TEXT.': cut prose, keep the facts');
            }
        }
        $missing = array_diff($criteria, array_keys($parsed));
        if ($missing !== []) {
            throw new Invalid('every criterion needs a --check; missing: '.implode(', ', $missing));
        }
        ksort($parsed);
        $issues = array_values(array_filter(array_map('trim', $issues), fn (string $i) => $i !== ''));
        foreach ($issues as $issue) {
            if (($length = mb_strlen($issue)) > self::VERDICT_TEXT) {
                throw new Invalid("--issue: {$length} characters, at most ".self::VERDICT_TEXT.': cut prose, keep the facts');
            }
        }
        $failed = array_keys(array_filter($parsed, fn (array $c) => $c['result'] === 'fail'));
        if ($decision === 'approve' && ($failed !== [] || $issues !== [])) {
            throw new Invalid('approve needs every check passing and no --issue'.($failed !== [] ? ' (failing: '.implode(', ', $failed).')' : ''));
        }
        if ($decision === 'reject' && $failed === [] && $issues === []) {
            throw new Invalid('reject needs a failing --check or an --issue');
        }

        return self::seal([
            'card' => $card->id(),
            'decision' => $decision,
            'checks' => array_combine(array_map('strval', array_keys($parsed)), array_values($parsed)),
            'issues' => $issues,
            'discovered' => array_map(fn (string $d) => self::discovered($d), $discovered),
            'upstream' => $upstream,
            'note' => self::text($note),
        ] + $at);
    }

    /**
     * The plan of a card in planning: ready with the plan (Plan::check passed) and `content`, the hash of the card it covers,
     * or blocked with a reason or an Open question.
     *
     * @param  list<string>  $discovered  `type: Title — body`
     * @param  array{head: string, base: ?string, claim: ?string, after: ?string, worktree: string, session: ?string}  $at  base: the main commit the clone was made from; after: the card's last plan entry
     * @param  list<array{title: string, body: string}>  $upstream
     * @param  list<string>  $questions  question sections for the card's body (Policy\Questions::file)
     * @return array<string, mixed>
     */
    public static function plan(Card $card, string $status, ?string $plan, ?string $reason, array $discovered, ?string $note, array $at,
        array $upstream = [], array $questions = []): array
    {
        if (! in_array($status, ['ready', 'blocked'], true)) {
            throw new Invalid("--status must be ready or blocked, not '{$status}'");
        }
        $reason = self::text($reason);
        if ($status === 'ready' && $plan === null) {
            throw new Invalid('a plan needs --plan-file (the file you wrote it in, in your card\'s .tmp)');
        }
        if ($status === 'blocked' && $reason === null) {
            throw new Invalid('a blocked plan needs --reason (what keeps the card from being planned) or an Open question in --question-file');
        }
        if ($reason !== null && mb_strlen($reason) > 480) {
            throw new Invalid('--reason must be at most 480 characters');
        }

        return self::seal([
            'card' => $card->id(),
            'status' => $status,
            'plan' => $status === 'ready' ? $plan : null,
            'content' => Plan::hash($card->data),
            'discovered' => array_map(fn (string $d) => self::discovered($d), $discovered),
            'upstream' => $upstream,
            'reason' => $reason,
            'note' => self::text($note),
        ] + ($questions === [] ? [] : ['questions' => $questions]) + $at);
    }

    /**
     * A merger's result for the merge in flight, $state (MergeState): resolved or fixed at the merge clone's `head`, back
     * to the card's worker, or main (the failed check fails on main too), with the note on what it did or found. The
     * merge's `lease`, `round` and the approved head it merges (`card_head`) name the merge it answers.
     *
     * @param  array<string, mixed>  $state
     * @param  array{head: ?string, session: ?string}  $at
     * @return array<string, mixed>
     */
    public static function merge(Card $card, string $outcome, ?string $note, array $state, array $at): array
    {
        if (! in_array($outcome, MergeState::OUTCOMES, true)) {
            throw new Invalid("the merge result is resolved, fixed, back or main, not '{$outcome}'");
        }
        $note = self::text($note) ?? throw new Invalid("{$outcome} needs --note=\"…\": ".match ($outcome) {
            'back' => 'the files or failing tests, and what the worker must decide or fix',
            'main' => '<command> — what fails on main',
            default => 'what you did, and why',
        });
        if (($length = mb_strlen($note)) > self::VERDICT_TEXT) {
            throw new Invalid("--note: {$length} characters, at most ".self::VERDICT_TEXT.': cut prose, keep the facts');
        }

        return self::seal([
            'card' => $card->id(),
            'outcome' => $outcome,
            'note' => $note,
            'card_head' => $state['head'] ?? null,
            'lease' => $state['lease'] ?? null,
            'round' => $state['round'] ?? null,
            'merger_rounds' => $state['merger_rounds'] ?? 0,
        ] + $at);
    }

    /**
     * `bug: Title — body` → {type, title, body}. The type is optional (feature). `main: <command> — body` is a failure
     * already on main: it files no card; the applier records it as a `main_red` entry for the main session. Its title is
     * the command, marked `main`: the command ends at the first em or en dash, since ` -- ` is shell syntax; one quoted in
     * backticks ends at its closing backtick, and any separator starts the body.
     *
     * @return array{type: string, title: string, body: string, main?: true}
     */
    public static function discovered(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^main\s*:\s*(.*)$/s', $text, $m) === 1) {
            return ['type' => 'bug', ...self::mainCommand($text, trim($m[1])), 'main' => true];
        }
        $type = 'feature';
        if (preg_match('/^([a-z]+)\s*:\s*(.+)$/s', $text, $m) === 1) {
            if (! in_array($m[1], CardType::values(), true)) {
                throw new Invalid("--discovered '{$text}': type must be one of ".implode(', ', CardType::values()));
            }
            [$type, $text] = [$m[1], trim($m[2])];
        }
        [$title, $body] = self::split($text);
        if ($title === '' || mb_strlen($title) > 120) {
            throw new Invalid("--discovered '{$text}': the title must be 1-120 characters (\"type: Title — body\")");
        }

        return ['type' => $type, 'title' => $title, 'body' => $body];
    }

    /**
     * `Title — body` → [title, body]: split at the first em dash, en dash or ` -- ` between spaces.
     *
     * @return array{string, string}
     */
    public static function split(string $text): array
    {
        return array_map('trim', preg_split('/\s+[—–]\s+|\s+--\s+/u', trim($text), 2) + [1 => '']);
    }

    /** @return array{title: string, body: string} */
    private static function mainCommand(string $text, string $rest): array
    {
        $usage = "--discovered '{$text}': ";
        if (str_starts_with($rest, '`')) {
            if (preg_match('/^`([^`]*)`(.*)$/s', $rest, $m) !== 1) {
                throw new Invalid($usage.'the command\'s backtick is never closed ("main: `<command>` — what fails")');
            }
            [$command, $body] = [trim($m[1]), trim(preg_replace('/^\s*(?:[—–]|--)(?=\s|$)/u', '', $m[2]))];
        } else {
            [$command, $body] = array_map('trim', preg_split('/\s+[—–]\s+/u', $rest, 2) + [1 => '']);
            if (str_contains($command, '`')) {
                throw new Invalid($usage.'quote the whole command in backticks, or none of it ("main: `<command>` — what fails")');
            }
        }
        if ($command === '' || mb_strlen($command) > 200) {
            throw new Invalid($usage.'name the command that fails on main, 1-200 characters ("main: <command> — what fails")');
        }

        return ['title' => $command, 'body' => $body];
    }

    /**
     * Content hash: identical staged content applies once.
     *
     * @param  array<string, mixed>  $item
     */
    public static function hash(array $item): string
    {
        $content = array_diff_key($item, ['staged_at' => 1, 'hash' => 1, 'session' => 1, 'gates' => 1]);
        ksort($content);

        return substr(hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function seal(array $item): array
    {
        $item['staged_at'] = Clock::now();
        $item['hash'] = self::hash($item);

        return $item;
    }

    /**
     * @param  list<string>  $ticks  ids, comma lists allowed
     * @return list<int>
     */
    private static function ticks(Card $card, array $ticks): array
    {
        $criteria = array_column($card->acceptance(), 'id');
        $out = [];
        foreach ($ticks as $tick) {
            foreach (array_filter(array_map('trim', explode(',', $tick)), fn ($t) => $t !== '') as $t) {
                if (! ctype_digit($t) || ! in_array((int) $t, $criteria, true)) {
                    throw new Invalid("--tick {$t}: {$card->id()} has no criterion {$t} (criteria: ".(implode(', ', $criteria) ?: 'none').')');
                }
                $out[] = (int) $t;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private static function text(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
