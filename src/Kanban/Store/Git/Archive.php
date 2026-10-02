<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

/**
 * `decisions.md` at the board root: the owner's decisions, read only, one `### ID — Title` section each, sorted by date
 * then id. It lies outside the card glob and the `*.json merge=kanban` attribute. Its content is a function of what was
 * archived: a section whose id is already there is never written again.
 *
 * @phpstan-type Entry array{id: string, title: string, date: string, facts: list<string>, body: string, why: string, resolution: string|null}
 */
final class Archive
{
    public const PATH = 'decisions.md';

    private const HEADER = "# Decisions\n\nThe owner's decisions, kept read only. The ones that still bind are rules in the CLAUDE.md files; an open\nquestion rides on the card it holds up, as a `question:` block.";

    private const HEADING = '/^### (\S+) — /m';

    /** @return array<string, true> the ids that already have a section */
    public static function ids(?string $bytes): array
    {
        preg_match_all(self::HEADING, (string) $bytes, $m);

        return array_fill_keys($m[1], true);
    }

    /**
     * $bytes with a section for each entry whose id is not there yet and each $preamble block it lacks; the same bytes
     * (null for no file) when nothing is new.
     *
     * @param  list<Entry>  $entries
     * @param  list<string>  $preamble  text for the top of the file, before the sections
     */
    public static function add(?string $bytes, array $entries, array $preamble = []): ?string
    {
        $known = self::ids($bytes);
        $entries = array_values(array_filter($entries, fn (array $entry) => ! isset($known[$entry['id']])));
        $preamble = array_values(array_filter(array_map('trim', $preamble), fn (string $block) => $block !== '' && ! str_contains((string) $bytes, $block)));
        if ($entries === [] && $preamble === []) {
            return $bytes;
        }
        [$top, $sections] = self::split($bytes ?? '');
        foreach ($entries as $entry) {
            $sections[] = [$entry['date'], $entry['id'], self::section($entry)];
        }
        usort($sections, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode("\n\n", [$top === '' ? self::HEADER : $top, ...$preamble, ...array_column($sections, 2)])."\n";
    }

    /** @param  Entry  $entry */
    private static function section(array $entry): string
    {
        $parts = ["### {$entry['id']} — {$entry['title']}", '*'.implode(' · ', [$entry['date'], ...$entry['facts']]).'*'];
        if (trim($entry['body']) !== '') {
            $parts[] = self::text($entry['body']);
        }
        if (trim($entry['why']) !== '') {
            $parts[] = '**Why:** '.self::text($entry['why']);
        }
        if (trim((string) $entry['resolution']) !== '') {
            $parts[] = '**Resolution:** '.self::text((string) $entry['resolution']);
        }

        return implode("\n\n", $parts);
    }

    /** Trimmed, with a line that would start a heading escaped, so it never opens a section of its own. */
    private static function text(string $text): string
    {
        return (string) preg_replace('/^(?=#)/m', '\\', trim(str_replace("\r\n", "\n", $text)));
    }

    /**
     * The text before the first section, and each section as [date, id, text].
     *
     * @return array{0: string, 1: list<array{0: string, 1: string, 2: string}>}
     */
    private static function split(string $bytes): array
    {
        $chunks = preg_split('/^(?=### \S+ — )/m', $bytes);
        $top = trim((string) array_shift($chunks));
        $sections = [];
        foreach ($chunks as $chunk) {
            preg_match(self::HEADING, $chunk, $id);
            preg_match('/^\*(\d{4}-\d{2}-\d{2})/m', $chunk, $date);
            $sections[] = [$date[1] ?? '', $id[1] ?? '', trim($chunk)];
        }

        return [$top, $sections];
    }
}
