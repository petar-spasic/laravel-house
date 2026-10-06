<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * Questions for the owner, as sections of a card's body:
 *
 *     ## Open question (2026-10-05)            blocks the card
 *     ## Provisional decision (2026-10-05)     the agent went ahead with `Taken`
 *     What is decided, in plain words.
 *     1. Option — what it means for users     (2–4 options)
 *     Recommended: 1 — why
 *     Taken: 1                                 (a provisional decision only)
 *
 * A section is answered when the next `##` section is `## Owner answer (date)`. An Open question without options is an
 * older free-form one, answered with a note.
 */
final class Questions
{
    public const OPEN = 'Open question';

    public const PROVISIONAL = 'Provisional decision';

    public const ANSWER = 'Owner answer';

    /** The line of the question `finish --ask` puts, naming the files an approval covers. */
    public const STEERING = 'Steering:';

    private const HEADING = '/^## (Open question|Provisional decision)(?: \(([^)]*)\))?\s*$/';

    /**
     * The question sections of a question file, validated: what `report --question-file` stages.
     *
     * @return list<string> each section's text, its heading dated today when it had no date
     */
    public static function file(string $markdown, string $status): array
    {
        $sections = self::sections($markdown);
        if ($sections === []) {
            throw new Invalid('the question file has no ## Open question or ## Provisional decision section');
        }
        $out = [];
        foreach ($sections as $section) {
            $kind = $section['kind'];
            $where = "## {$kind}";
            if ($section['context'] === '') {
                throw new Invalid("{$where}: a line before the options says what is decided, in plain words");
            }
            if (self::steering($section) !== [] || str_contains("\n".$section['text'], "\n".self::STEERING)) {
                throw new Invalid("{$where}: a `".self::STEERING."` line is kanban's own (finish asks the owner with it); leave it out");
            }
            $count = count($section['options']);
            if ($count < 2 || $count > 4 || array_keys($section['options']) !== range(1, $count)) {
                throw new Invalid("{$where} needs 2 to 4 numbered options (1. Option — what it means for users)");
            }
            if ($section['recommended'] === null || ! isset($section['options'][$section['recommended']])) {
                throw new Invalid("{$where}: Recommended: names no option (Recommended: N — why)");
            }
            if ($kind === self::OPEN && $section['taken'] !== null) {
                throw new Invalid("{$where}: Taken: belongs to a Provisional decision");
            }
            if ($kind === self::PROVISIONAL && ($section['taken'] === null || ! isset($section['options'][$section['taken']]))) {
                throw new Invalid("{$where}: Taken: names the option you went ahead with");
            }
            if ($kind === self::OPEN && $status === 'review') {
                throw new Invalid('an Open question blocks the card: report --status=blocked');
            }
            $out[] = '## '.$kind.' ('.($section['ref'] ?? gmdate('Y-m-d')).")\n".$section['text'];
        }

        return $out;
    }

    /** The first Open question's context line: the card's block. */
    public static function block(array $sections): ?string
    {
        foreach ($sections as $text) {
            $section = self::sections($text)[0] ?? null;
            if ($section !== null && $section['kind'] === self::OPEN) {
                return Card::QUESTION.mb_strimwidth(strtok($section['context'], "\n"), 0, 470, '…');
            }
        }

        return null;
    }

    /**
     * The files a steering question (`finish --ask`) asks about, or [] for any other question.
     *
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    public static function steering(array $question): array
    {
        foreach (explode("\n", (string) $question['context']) as $line) {
            if (str_starts_with($line, self::STEERING)) {
                return array_values(array_filter(array_map('trim', explode(',', substr($line, strlen(self::STEERING))))));
            }
        }

        return [];
    }

    /** $body with the sections it does not hold yet appended. */
    public static function append(string $body, array $sections): string
    {
        foreach ($sections as $text) {
            if (! self::holds($body, substr($text, strpos($text, "\n") + 1))) {
                $body = trim(rtrim($body)."\n\n".rtrim($text));
            }
        }

        return $body;
    }

    /**
     * $body without the unanswered question sections $existing already holds, whatever their headings say (a date, a
     * decision's id): what a card folded into another keeps, so one question is asked once.
     */
    public static function without(string $body, string $existing): string
    {
        $chunks = preg_split('/^(?=## )/m', str_replace("\r\n", "\n", $body)) ?: [$body];
        $kept = [];
        foreach ($chunks as $i => $chunk) {
            [$heading, $content] = explode("\n", $chunk, 2) + [1 => ''];
            if (preg_match(self::HEADING, $heading) === 1 && ! str_starts_with($chunks[$i + 1] ?? '', '## '.self::ANSWER) && self::holds($existing, $content)) {
                continue;
            }
            $kept[] = $chunk;
        }

        return trim(implode('', $kept));
    }

    /** How many Open questions of $body have no answer. */
    public static function unanswered(string $body): int
    {
        return count(array_filter(self::sections($body), fn (array $s) => $s['kind'] === self::OPEN && ! $s['answered']));
    }

    /**
     * What waits for the owner: the open questions of cards not done or dropped (a finished card's are moot), and the
     * provisional decisions of every card not dropped until they are answered, merged work included.
     *
     * @return list<array{card: Card, question: array<string, mixed>}>
     */
    public static function pending(Snapshot $snapshot): array
    {
        $pending = [];
        foreach ($snapshot->cards(fn (Card $c) => $c->stage() !== 'dropped') as $card) {
            foreach (self::open($card) as $question) {
                if ($question['kind'] === self::PROVISIONAL || $card->stage() !== 'done') {
                    $pending[] = ['card' => $card, 'question' => $question];
                }
            }
        }

        return $pending;
    }

    /**
     * `2 open, 1 provisional`: the one count `questions`, `status` and `morning` print.
     *
     * @param  list<array{card: Card, question: array<string, mixed>}>  $pending
     */
    public static function tally(array $pending): string
    {
        $provisional = count(array_filter($pending, fn (array $p) => $p['question']['kind'] === self::PROVISIONAL));

        return (count($pending) - $provisional).' open'.($provisional === 0 ? '' : ", {$provisional} provisional");
    }

    /**
     * The open questions of a card, in body order. A `question:` block without an open section is one too (n 0).
     *
     * @return list<array{n: int, kind: string, context: string, options: array<int, string>, recommended: ?int, taken: ?int, text: string}>
     */
    public static function open(Card $card): array
    {
        $open = [];
        foreach (self::sections((string) ($card->data['body'] ?? '')) as $n => $section) {
            if (! $section['answered']) {
                $open[] = ['n' => $n + 1] + $section;
            }
        }
        if ($card->asks() && array_filter($open, fn (array $q) => $q['kind'] === self::OPEN) === []) {
            $text = substr((string) $card->blocked(), strlen(Card::QUESTION));
            array_unshift($open, ['n' => 0, 'kind' => self::OPEN, 'context' => $text, 'options' => [], 'recommended' => null, 'taken' => null, 'text' => $text, 'answered' => false, 'ref' => null]);
        }

        return $open;
    }

    /**
     * The open question `<ID>#<n>` (or `<ID>` when the card has exactly one).
     *
     * @return array<string, mixed>
     */
    public static function find(Card $card, ?int $n): array
    {
        $open = self::open($card);
        if ($n === null) {
            if (count($open) !== 1) {
                throw new PolicyRefused($open === [] ? "{$card->id()} has no open question" : "{$card->id()} has ".count($open).' open questions: name one as '.$card->id().'#<n>');
            }

            return $open[0];
        }
        foreach ($open as $question) {
            if ($question['n'] === $n) {
                return $question;
            }
        }

        throw new NotFound("{$card->id()} has no open question #{$n}");
    }

    /** $body with the owner's answer under question $n (at the end for a bare `question:` block). */
    public static function answer(string $body, int $n, string $answer): string
    {
        $section = '## '.self::ANSWER.' ('.gmdate('Y-m-d').")\n".$answer;
        if ($n === 0) {
            return trim(rtrim($body)."\n\n".$section);
        }
        $lines = explode("\n", $body);
        $seen = 0;
        $at = null;
        foreach ($lines as $i => $line) {
            if ($at === null && preg_match(self::HEADING, $line) === 1 && ++$seen === $n) {
                $at = count($lines);
            } elseif ($at !== null && str_starts_with($line, '## ')) {
                $at = $i;
                break;
            }
        }
        $before = rtrim(implode("\n", array_slice($lines, 0, $at)));
        $after = ltrim(implode("\n", array_slice($lines, $at)), "\n");

        return $before."\n\n".$section.($after === '' ? '' : "\n\n".$after);
    }

    private static function holds(string $body, string $content): bool
    {
        $content = trim($content);

        return $content !== '' && str_contains($body, $content);
    }

    /**
     * Every question section of a Markdown text, in order.
     *
     * @return list<array{kind: string, ref: ?string, context: string, options: array<int, string>, recommended: ?int, taken: ?int, text: string, answered: bool}>
     */
    private static function sections(string $markdown): array
    {
        $sections = [];
        $current = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            if (str_starts_with($line, '## ')) {
                $follows = $current !== null;
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = null;
                if (preg_match(self::HEADING, $line, $m) === 1) {
                    $current = ['kind' => $m[1], 'ref' => ($m[2] ?? '') === '' ? null : $m[2], 'lines' => []];
                } elseif ($follows && str_starts_with($line, '## '.self::ANSWER)) {
                    $sections[count($sections) - 1]['answered'] = true;
                }

                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(fn (array $s) => self::section($s), $sections);
    }

    /** @param  array<string, mixed>  $raw */
    private static function section(array $raw): array
    {
        $context = [];
        $options = [];
        $recommended = $taken = null;
        foreach ($raw['lines'] as $line) {
            $line = trim($line);
            if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $m) === 1) {
                $options[(int) $m[1]] = $m[2];
            } elseif (preg_match('/^Recommended:\s*(\d+)/i', $line, $m) === 1) {
                $recommended = (int) $m[1];
            } elseif (preg_match('/^Taken:\s*(\d+)/i', $line, $m) === 1) {
                $taken = (int) $m[1];
            } elseif ($line !== '' && $options === []) {
                $context[] = $line;
            }
        }

        return [
            'kind' => $raw['kind'], 'ref' => $raw['ref'], 'context' => implode("\n", $context), 'options' => $options,
            'recommended' => $recommended, 'taken' => $taken, 'text' => trim(implode("\n", $raw['lines'])), 'answered' => $raw['answered'] ?? false,
        ];
    }
}
