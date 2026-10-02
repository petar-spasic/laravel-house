<?php

namespace PetarSpasic\LaravelHouse\Kanban\Import;

/**
 * `docs/decisions.md` of the house docs: one bullet per decision, `- **YYYY-MM-DD — Title.** Body. Why: reason.`
 *
 * @phpstan-type Entry array{line: int, raw: string, date: string, stage: string, title: string, body: string, why: string, decided_on: ?string, resolution: ?string, supersession: bool}
 * @phpstan-type Warning array{line: int, message: string, unparsed: bool}
 */
final class DecisionsMarkdown
{
    public const ENTRY = '/^- \*\*(\d{4}-\d{2}-\d{2})\s+[—–-]\s+(.+?)\*\*\s*(.*)$/u';

    private const BOLD = '/^\*\*(\d{4}-\d{2}-\d{2})\s+[—–-]\s+(.+?)\*\*\s*(.*)$/u';

    private const WHY = '/(?:^|\s)Why(?:\s*\([^)]*\))?:\s*/u';

    private const SUPERSESSION = '/\bSupersedes\b|\bSuperseded\b.*\bby\b/iu';

    private const TITLE_MAX = 120;

    /** @return array{entries: list<Entry>, warnings: list<Warning>} */
    public static function parse(string $markdown): array
    {
        $entries = [];
        $warnings = [];
        $header = true;
        foreach (preg_split('/\r?\n/', $markdown) as $index => $raw) {
            $line = $index + 1;
            $text = trim($raw);
            if ($header && ! str_starts_with($text, '- ') && ! str_starts_with($text, '**')) {
                continue;
            }
            $header = false;
            if ($text === '') {
                continue;
            }
            $bullet = preg_match(self::ENTRY, $text, $m) === 1;
            if (! $bullet && preg_match(self::BOLD, $text, $m) !== 1) {
                $warnings[] = ['line' => $line, 'message' => 'not a decision line, skipped: '.self::cut($text, 80), 'unparsed' => true];

                continue;
            }
            if (! $bullet) {
                $warnings[] = ['line' => $line, 'message' => 'bold paragraph instead of a bullet, imported as decided', 'unparsed' => false];
            }
            [$body, $why] = self::splitWhy($m[3]);
            $title = self::title($m[2], $line, $warnings);
            $entries[] = [
                'line' => $line, 'raw' => $raw, 'date' => $m[1], 'stage' => 'decided', 'title' => $title, 'body' => $body, 'why' => $why,
                'decided_on' => $m[1], 'resolution' => null, 'supersession' => preg_match(self::SUPERSESSION, $text) === 1,
            ];
        }

        return ['entries' => $entries, 'warnings' => $warnings];
    }

    /**
     * Body and why: `why` is the text after the last `Why:` / `Why (…):`, the body what precedes it.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitWhy(string $text): array
    {
        if (preg_match_all(self::WHY, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [trim($text), ''];
        }
        [$marker, $offset] = end($matches[0]);

        return [trim(substr($text, 0, $offset)), trim(substr($text, $offset + strlen($marker)))];
    }

    /** A title without its trailing period, cut at a word when longer than a card title may be. */
    public static function title(string $text, int $line, array &$warnings): string
    {
        $title = preg_replace('/\.$/u', '', trim($text));
        if (mb_strlen($title) > self::TITLE_MAX) {
            $warnings[] = ['line' => $line, 'message' => 'title longer than '.self::TITLE_MAX.' characters, cut', 'unparsed' => false];
            $title = self::cut($title, self::TITLE_MAX);
        }

        return $title;
    }

    /** $text cut to at most $max characters at a word boundary, marked with an ellipsis. */
    public static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return preg_replace('/[\s,;:.—–-]+$/u', '', $cut).'…';
    }
}
