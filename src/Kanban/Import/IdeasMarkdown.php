<?php

namespace PetarSpasic\Kanban\Import;

/**
 * `docs/ideas.md` of the house docs: the `| date | idea | status |` table, one idea per row.
 *
 * @phpstan-import-type Entry from DecisionsMarkdown
 * @phpstan-import-type Warning from DecisionsMarkdown
 */
final class IdeasMarkdown
{
    private const HEADER = '/^\|?\s*date\s*\|\s*idea\s*\|\s*status\s*\|?$/iu';

    private const SEPARATOR = '/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?$/';

    private const TITLE_MAX = 100;

    /** @return array{entries: list<Entry>, warnings: list<Warning>} */
    public static function parse(string $markdown): array
    {
        $lines = preg_split('/\r?\n/', $markdown);
        $start = null;
        foreach ($lines as $index => $raw) {
            if (preg_match(self::HEADER, trim($raw)) === 1) {
                $start = $index + 1;

                break;
            }
        }
        if ($start === null) {
            return ['entries' => [], 'warnings' => [['line' => 1, 'message' => 'no `| date | idea | status |` table found', 'unparsed' => true]]];
        }
        if (preg_match(self::SEPARATOR, trim($lines[$start] ?? '')) === 1) {
            $start++;
        }

        $entries = [];
        $warnings = [];
        for ($index = $start; $index < count($lines) && str_starts_with(trim($lines[$index]), '|'); $index++) {
            $raw = $lines[$index];
            $line = $index + 1;
            $cells = self::cells(trim($raw));
            if (count($cells) !== 3 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $cells[0]) !== 1 || $cells[1] === '') {
                $warnings[] = ['line' => $line, 'message' => 'not a `| date | idea | status |` row, skipped: '.DecisionsMarkdown::cut(trim($raw), 80), 'unparsed' => true];

                continue;
            }
            [$date, $idea, $status] = $cells;
            [$title, $rest] = self::title($idea, $line, $warnings);
            [$body, $why] = DecisionsMarkdown::splitWhy($rest);
            $entry = ['line' => $line, 'raw' => $raw, 'date' => $date, 'stage' => 'proposed', 'title' => $title, 'body' => $body, 'why' => $why,
                'decided_on' => null, 'resolution' => null, 'supersession' => false];

            if (preg_match('/^decided\b\s*(\d{4}-\d{2}-\d{2})?\s*:?\s*(.*)$/iu', $status, $m) === 1) {
                $entry = ['stage' => 'decided', 'decided_on' => $m[1] !== '' ? $m[1] : $date, 'resolution' => $m[2] !== '' ? $m[2] : null] + $entry;
            } elseif (preg_match('/^dropped\b\s*(?:\d{4}-\d{2}-\d{2})?\s*:?\s*(.*)$/iu', $status, $m) === 1) {
                $entry = ['stage' => 'dropped', 'resolution' => $m[1] !== '' ? $m[1] : 'Dropped in ideas.md'] + $entry;
            } elseif (strcasecmp($status, 'floated') !== 0) {
                $warnings[] = ['line' => $line, 'message' => "unknown status '{$status}', imported as proposed", 'unparsed' => false];
            }
            $entries[] = $entry;
        }

        return ['entries' => $entries, 'warnings' => $warnings];
    }

    /**
     * Cells of a table row: split on `|` that is neither escaped nor inside backticks; `\|` becomes `|`.
     *
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $cells = [];
        $cell = '';
        $code = false;
        for ($i = 0, $length = strlen($row); $i < $length; $i++) {
            $char = $row[$i];
            if ($char === '\\' && ($row[$i + 1] ?? '') === '|') {
                $cell .= '|';
                $i++;

                continue;
            }
            if ($char === '`') {
                $code = ! $code;
            }
            if ($char === '|' && ! $code) {
                $cells[] = $cell;
                $cell = '';

                continue;
            }
            $cell .= $char;
        }
        $cells[] = $cell;
        if (str_starts_with($row, '|')) {
            array_shift($cells);
        }
        if (count($cells) > 0 && trim(end($cells)) === '' && str_ends_with($row, '|')) {
            array_pop($cells);
        }

        return array_map('trim', $cells);
    }

    /**
     * Title = the leading `**…**`, else the first sentence (≤ 100 characters, cut at a word). Returns [title, rest].
     *
     * @param  list<Warning>  $warnings
     * @return array{0: string, 1: string}
     */
    private static function title(string $idea, int $line, array &$warnings): array
    {
        if (preg_match('/^\*\*(.+?)\*\*\s*(.*)$/u', $idea, $m) === 1) {
            return [DecisionsMarkdown::title($m[1], $line, $warnings), $m[2]];
        }
        if (preg_match('/^(.+?)[.!?](?:\s+(.*))?$/u', $idea, $m) === 1 && mb_strlen($m[1]) <= self::TITLE_MAX) {
            return [trim($m[1]), $m[2] ?? ''];
        }

        return [DecisionsMarkdown::cut(preg_replace('/\.$/u', '', $idea), self::TITLE_MAX), $idea];
    }
}
