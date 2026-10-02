<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\CardType;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;

/** Builds and validates the staged report (worker) and verdict (evaluator) of a card. */
final class Staged
{
    /**
     * @param  list<string>  $ticks
     * @param  list<string>  $verified
     * @param  list<string>  $discovered  `type: Title — body`
     * @param  array{head: string, worktree: string, session: ?string}  $at
     * @return array<string, mixed>
     */
    public static function report(Card $card, string $status, array $ticks, ?string $summary, array $verified, array $discovered,
        ?string $reason, ?string $note, array $at): array
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
            'reason' => $reason,
            'note' => self::text($note),
        ] + $at;

        return self::seal($item);
    }

    /**
     * @param  list<string>  $checks  `N:pass|fail:evidence`
     * @param  list<string>  $issues
     * @param  list<string>  $discovered  `type: Title — body`; outside the card, so they never decide the verdict
     * @param  array{head: string, base: ?string, worktree: string, session: ?string}  $at
     * @return array<string, mixed>
     */
    public static function verdict(Card $card, string $decision, array $checks, array $issues, array $discovered, ?string $note, array $at): array
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
        }
        $missing = array_diff($criteria, array_keys($parsed));
        if ($missing !== []) {
            throw new Invalid('every criterion needs a --check; missing: '.implode(', ', $missing));
        }
        ksort($parsed);
        $issues = array_values(array_filter(array_map('trim', $issues), fn (string $i) => $i !== ''));
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
            'note' => self::text($note),
        ] + $at);
    }

    /**
     * `bug: Title — body` → {type, title, body}. The type is optional (feature).
     *
     * @return array{type: string, title: string, body: string}
     */
    public static function discovered(string $text): array
    {
        $text = trim($text);
        $type = 'feature';
        if (preg_match('/^([a-z]+)\s*:\s*(.+)$/s', $text, $m) === 1) {
            if (! in_array($m[1], CardType::values(), true)) {
                throw new Invalid("--discovered '{$text}': type must be one of ".implode(', ', CardType::values()));
            }
            [$type, $text] = [$m[1], trim($m[2])];
        }
        [$title, $body] = array_map('trim', preg_split('/\s+[—–]\s+|\s+--\s+/u', $text, 2) + [1 => '']);
        if ($title === '' || mb_strlen($title) > 120) {
            throw new Invalid("--discovered '{$text}': the title must be 1-120 characters (\"type: Title — body\")");
        }

        return ['type' => $type, 'title' => $title, 'body' => $body];
    }

    /**
     * Content hash: identical staged content applies once.
     *
     * @param  array<string, mixed>  $item
     */
    public static function hash(array $item): string
    {
        $content = array_diff_key($item, ['staged_at' => 1, 'hash' => 1, 'session' => 1]);
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
