<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;

/**
 * A card's plan: the Markdown its planner writes for the worker, checked before it is staged.
 *
 *     ## Files                    every path the work reads or touches, each looked up in git
 *     - read `app/Models/Note.php` — what the work reuses there
 *     - change `routes/web.php` — what changes
 *     - create `tests/Feature/ExportTest.php` — what it holds
 *     ## Steps                    numbered, each ending with the command that checks it
 *     1. …
 *     ## Criteria                 one item per acceptance criterion, its proof in a code span
 *     - 1: `tests/Feature/ExportTest.php` asserts …; `php artisan test --compact --filter=Export`
 *
 * It says what to build, where, in which order and how to check it; the worker writes the code. hints() names where a
 * plan runs long, writes the code or runs docker, and never refuses one: the planner judges.
 *
 * A plan is current while it covers the card as it is: its `planned` entry holds the hash of the card's content then.
 */
final class Plan
{
    public const VERBS = ['create', 'change', 'delete', 'read'];

    /** Past these a plan gets a hint: its length, its steps, and code beyond contracts of a few lines. */
    public const HINT_CHARS = 8000;

    public const HINT_STEPS = 20;

    public const HINT_BLOCK_LINES = 12;

    public const HINT_CODE_LINES = 40;

    public const HINT_SPAN = 200;

    private const CODE = 'the plan pins contracts (a signature, a route, columns) and names a file whose pattern to copy; the worker writes the code';

    /** A card's shell runs inside its container, which has no docker of its own. */
    private const SHELL = 'a docker command: the card\'s shell runs inside its container, which has no docker; write the command as it runs there (`php artisan test …`, not `docker compose exec app php artisan test …`), unless the context says `shell on this machine`';

    private const REQUIRED = ['Files', 'Steps', 'Criteria'];

    private const FILE = '/^- (create|change|delete|read) `([^`]+)`\s+[—–-]+\s+\S/u';

    /**
     * The plan, trimmed, once it has every section, a file line for each path it names that git confirms, and one criterion
     * line per id of $criteria; Invalid names the first thing to fix otherwise.
     *
     * @param  list<int>  $criteria  the card's criterion ids
     * @param  callable(string): bool  $exists  whether a path (file or directory) is in the commit the plan is made on
     */
    public static function check(string $markdown, array $criteria, callable $exists): string
    {
        $plan = trim(str_replace("\r\n", "\n", $markdown));
        if ($plan === '') {
            throw new Invalid('the plan is empty');
        }
        if (mb_strlen($plan) > Card::MAX_PLAN) {
            throw new Invalid('the plan is '.mb_strlen($plan).' characters; the limit is '.Card::MAX_PLAN.': cut prose, keep the facts');
        }
        $sections = self::sections($plan);
        foreach (self::REQUIRED as $heading) {
            if (! isset($sections[$heading])) {
                throw new Invalid("the plan has no `## {$heading}` section");
            }
        }

        $files = 0;
        foreach (self::items($sections['Files']) as $item) {
            if (preg_match(self::FILE, $item, $m) !== 1) {
                throw new Invalid("## Files: '".mb_strimwidth(strtok($item, "\n") ?: '', 0, 120, '…')."' is not a file line: - create|change|delete|read `path` — why");
            }
            $path = rtrim($m[2], '/');
            if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || preg_match('/[*?]/', $path) === 1
                || $path === '.git' || str_starts_with($path, '.git/') || str_starts_with($path, '.claude/worktrees')) {
                throw new Invalid("## Files: `{$m[2]}`: name one path relative to the repository root, outside .git and .claude/worktrees, without wildcards");
            }
            $there = $exists($path);
            if ($m[1] === 'create' && $there) {
                throw new Invalid("## Files: `{$path}` already exists: `change` it, or `create` a new path");
            }
            if ($m[1] !== 'create' && ! $there) {
                throw new Invalid("## Files: `{$path}` is tracked neither on main nor on the card's branch: {$m[1]} names a tracked file or directory (check the path; a new file is `create`)");
            }
            $files++;
        }
        if ($files === 0) {
            throw new Invalid('## Files lists no file: - create|change|delete|read `path` — why');
        }

        if (preg_grep('/^\d+\.\s+\S/', $sections['Steps']) === []) {
            throw new Invalid('## Steps has no numbered step (1. what to do; the command that checks it)');
        }

        $covered = [];
        foreach (self::items($sections['Criteria']) as $item) {
            if (preg_match('/^- (\d+):\s*(\S.*)$/s', $item, $m) !== 1) {
                throw new Invalid("## Criteria: '".mb_strimwidth(strtok($item, "\n") ?: '', 0, 120, '…')."' is not a criterion line: - N: how it is proven, the test or command in `code`");
            }
            $n = (int) $m[1];
            if (! in_array($n, $criteria, true)) {
                throw new Invalid("## Criteria: {$n} is not a criterion of the card (criteria: ".(implode(', ', $criteria) ?: 'none').')');
            }
            if (isset($covered[$n])) {
                throw new Invalid("## Criteria: criterion {$n} appears twice");
            }
            if (preg_match('/`[^`\n]+`/', $m[2]) !== 1) {
                throw new Invalid("## Criteria: criterion {$n} names no proof in a code span (the test, the entry point or the command)");
            }
            $covered[$n] = true;
        }
        $missing = array_values(array_diff($criteria, array_keys($covered)));
        if ($missing !== []) {
            throw new Invalid('## Criteria has no line for criterion '.implode(', ', $missing).' (- N: how it is proven, the test or command in `code`)');
        }

        return $plan;
    }

    /**
     * The `## Files` lines of a plan, as written.
     *
     * @return list<array{0: string, 1: string}> [verb, path]
     */
    public static function files(string $plan): array
    {
        $files = [];
        foreach (self::items(self::sections(str_replace("\r\n", "\n", $plan))['Files'] ?? []) as $item) {
            if (preg_match(self::FILE, $item, $m) === 1) {
                $files[] = [$m[1], rtrim($m[2], '/')];
            }
        }

        return $files;
    }

    /**
     * What a plan covers: the criteria and the body, whitespace aside, without the questions kanban wrote and the owner's
     * confirmations of what a Provisional decision took (Questions::strip). Any other answer changes it.
     *
     * @param  array<string, mixed>  $data  card data
     */
    public static function hash(array $data): string
    {
        $text = fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', $s));
        $criteria = array_map(fn (array $c) => [(int) $c['id'], $text((string) $c['text'])], array_values($data['acceptance'] ?? []));

        return substr(hash('sha256', json_encode([$criteria, $text(Questions::strip((string) ($data['body'] ?? '')))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * The card has a plan that covers it as it is: made for its content now, and after any work its parked branch holds (half
     * done work has moved past a plan made before it started).
     */
    public static function current(Card $card): bool
    {
        $planned = $card->planned();
        if ($card->plan() === null || $planned === null || ($planned['hash'] ?? null) !== self::hash($card->data)) {
            return false;
        }
        $started = $card->lastEntered('doing');

        return ! is_string($card->work()['parked_branch'] ?? null) || $started === null || (string) ($planned['at'] ?? '') > $started;
    }

    /** Why the card a planner holds may not go to ready yet, or null once its planner's plan is on it and covers it. */
    public static function unreleased(Card $card): ?string
    {
        return match (true) {
            $card->stage() !== 'planning' || ! self::madeUnderClaim($card) => "{$card->id()} has no plan from its planner yet: it moves to ready once its planner's plan is applied",
            ! self::current($card) => "{$card->id()}'s plan no longer covers it (its criteria or body changed since): its planner revises it",
            default => null,
        };
    }

    /** Its planner's plan, made under the claim it holds now, is on the card: the planning is done once it is current. */
    public static function madeUnderClaim(Card $card): bool
    {
        $at = $card->claim()['at'] ?? null;

        return is_string($at) && $card->plan() !== null && (string) ($card->planned()['at'] ?? '') > $at;
    }

    /**
     * Where the plan runs long, writes the code or runs docker, one line each. None refuses the plan: a card may need it.
     *
     * @return list<string>
     */
    public static function hints(string $plan): array
    {
        $plan = trim(str_replace("\r\n", "\n", $plan));
        $hints = [];
        if (($length = mb_strlen($plan)) > self::HINT_CHARS) {
            $hints[] = "the plan is {$length} characters; most take 3000 to ".self::HINT_CHARS.': cut prose and code, keep the facts the worker cannot find quickly; a card that holds unrelated work is reported blocked, proposing the split';
        }
        if (($steps = count(preg_grep('/^\d+\.\s+\S/', self::sections($plan)['Steps'] ?? []))) > self::HINT_STEPS) {
            $hints[] = "## Steps has {$steps} steps; a step is one change the worker can check: merge the small ones";
        }
        $section = $fence = null;
        $from = $count = $total = 0;
        // a null line ends the plan, and a code block left open with it
        foreach ([...explode("\n", $plan), null] as $i => $line) {
            if ($fence !== null && ($line === null || preg_match('/^\s*'.preg_quote($fence[0], '/').'{'.strlen($fence).',}\s*$/', $line) === 1)) {
                if ($count > self::HINT_BLOCK_LINES) {
                    $hints[] = self::where($section, $from).": a code block of {$count} lines: ".self::CODE;
                }
                [$total, $fence] = [$total + $count, null];

                continue;
            }
            if ($line === null) {
                break;
            }
            if (preg_match('/\bdocker(?:-compose|\s+compose|\s+exec|\s+run)(?=\s)/', $line) === 1) {
                $hints[] = self::where($section, $i + 1).': '.self::SHELL;
            }
            if ($fence !== null) {
                $count++;

                continue;
            }
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m) === 1) {
                [$fence, $from, $count] = [$m[1], $i + 1, 0];

                continue;
            }
            if (preg_match('/^## (.+?)\s*$/', $line, $m) === 1) {
                $section = $m[1];
            }
            preg_match_all('/(`+)(.+?)\1/u', $line, $spans);
            foreach ($spans[2] as $span) {
                if (($length = mb_strlen(trim($span))) > self::HINT_SPAN) {
                    $hints[] = self::where($section, $i + 1).": an inline code span of {$length} characters: ".self::CODE;
                }
            }
        }
        if ($total > self::HINT_CODE_LINES) {
            $hints[] = "code blocks hold {$total} lines in all: ".self::CODE;
        }

        return $hints;
    }

    private static function where(?string $section, int $line): string
    {
        return ($section === null ? '' : "## {$section}, ")."line {$line}";
    }

    /**
     * Level-2 sections by heading, each a list of lines.
     *
     * @return array<string, list<string>>
     */
    private static function sections(string $plan): array
    {
        $sections = [];
        $current = null;
        foreach (explode("\n", $plan) as $line) {
            if (preg_match('/^## (.+?)\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] ??= [];

                continue;
            }
            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /**
     * The items of a section: each line that starts one, with the indented lines that continue it; blank lines end nothing.
     * Any other line is an item of its own, so a stray line is refused rather than read into one.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function items(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s/', $line) === 1 && $items !== []) {
                $items[count($items) - 1] .= "\n".trim($line);

                continue;
            }
            $items[] = rtrim($line);
        }

        return $items;
    }
}
