<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(fn () => $this->markTestSkipped('the import writes decision cards, which board version 2 no longer has; its rewrite to the archive and question blocks comes next'));

function houseDocs(Sandbox $sandbox, string $decisions = 'acme-decisions.md', string $ideas = 'acme-ideas.md'): void
{
    @mkdir($sandbox->root.'/docs', 0775, true);
    $fixtures = dirname(__DIR__, 2).'/Support/fixtures/import';
    copy("{$fixtures}/{$decisions}", $sandbox->root.'/docs/decisions.md');
    copy("{$fixtures}/{$ideas}", $sandbox->root.'/docs/ideas.md');
}

/** @return list<array<string, mixed>> the cards on project/decisions, by source file and line */
function importedCards(Sandbox $sandbox): array
{
    $cards = array_map(fn (string $file) => json_decode((string) file_get_contents($file), true), glob($sandbox->root.'/docs/kanban/project/decisions/*-*.json'));
    usort($cards, fn (array $a, array $b) => [$a['source']['file'], $a['source']['line']] <=> [$b['source']['file'], $b['source']['line']]);

    return $cards;
}

/** @return array<string, mixed> */
function importedAt(Sandbox $sandbox, string $file, int $line): array
{
    foreach (importedCards($sandbox) as $card) {
        if ($card['source']['file'] === $file && $card['source']['line'] === $line) {
            return $card;
        }
    }
    throw new RuntimeException("no card from {$file}:{$line}");
}

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('imports the house docs as decision cards in one commit', function () {
    houseDocs($this->sandbox);
    $commits = count($this->sandbox->boardLog());

    $out = $this->sandbox->ok('import-house-docs');

    expect($out)->toContain('imported 20: 9 decided, 10 proposed, 1 dropped (8 from docs/decisions.md, 12 from docs/ideas.md); 0 already imported')
        ->and($out)->not->toContain('warning:')
        ->and(count($this->sandbox->boardLog()))->toBe($commits + 1)
        ->and($this->sandbox->boardLog()[0])->toBe('Kanban: import house docs (20 cards) [owner]')
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and(importedCards($this->sandbox))->toHaveCount(20);

    $search = importedAt($this->sandbox, 'docs/decisions.md', 8);
    expect($search)->toMatchArray([
        'type' => 'decision', 'stage' => 'decided', 'decided_on' => '2026-09-27',
        'title' => 'Search is a Postgres full-text index, not a separate engine',
        'body' => 'Titles weigh above bodies (`setweight` A and B) and results never cross workspaces.',
        'why' => 'one datastore to run, and the volumes stay far below where it stops being enough.',
        'created' => '2026-09-27T00:00:00.000+00:00', 'resolution' => null, 'supersedes' => [],
    ])->and($search['source']['hash'])->toBe(sha1(file($this->sandbox->root.'/docs/decisions.md', FILE_IGNORE_NEW_LINES)[7]))
        ->and($search['log'][0])->toMatchArray(['by' => 'import', 'event' => 'created']);

    expect(importedAt($this->sandbox, 'docs/ideas.md', 16))->toMatchArray([
        'stage' => 'decided', 'decided_on' => '2026-09-27', 'title' => 'Full-text search without a search service',
        'resolution' => '(`decisions.md`, search is a Postgres index)',
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 17))->toMatchArray([
        'stage' => 'dropped', 'title' => 'Built-in video calls', 'resolution' => 'out of scope for a note app', 'decided_on' => null,
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 8))->toMatchArray([
        'stage' => 'proposed', 'title' => 'Offline-first editing', 'decided_on' => null,
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 8)['why'])->toStartWith('most notes are written on trains');

    expect($this->sandbox->kanban('validate')->getExitCode())->toBe(0);
});

it('writes nothing on a dry run and lists what it would import', function () {
    houseDocs($this->sandbox);
    $commits = $this->sandbox->boardLog();

    $out = $this->sandbox->ok(['import-house-docs', '--dry-run']);

    expect($out)->toContain("docs/decisions.md:7 decided 2026-09-28 A workspace has exactly one owner\n")
        ->and($out)->toContain("docs/ideas.md:16 decided 2026-09-20 Full-text search without a search service\n")
        ->and($out)->toContain('would import 20: 9 decided, 10 proposed, 1 dropped')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(importedCards($this->sandbox))->toBe([])
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');
});

it('creates nothing on a re-run', function () {
    houseDocs($this->sandbox);
    $this->sandbox->ok('import-house-docs');
    $commits = $this->sandbox->boardLog();

    $again = $this->sandbox->ok('import-house-docs');

    expect($again)->toContain('imported 0: 0 decided, 0 proposed, 0 dropped; 20 already imported')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(importedCards($this->sandbox))->toHaveCount(20)
        ->and($this->sandbox->ok(['import-house-docs', '--dry-run']))->toContain('would import 0:');
});

it('parses the edge cases and warns about what needs a hand', function () {
    houseDocs($this->sandbox, 'acme-decisions-edge.md', 'acme-ideas-edge.md');

    $out = $this->sandbox->ok('import-house-docs');

    expect($out)->toContain('imported 10: 6 decided, 3 proposed, 1 dropped (4 from docs/decisions.md, 6 from docs/ideas.md)')
        ->and($out)->toContain('warning: docs/decisions.md:7 bold paragraph instead of a bullet, imported as decided')
        ->and($out)->toContain('warning: docs/decisions.md:9 not a decision line, skipped: ## Older')
        ->and($out)->toContain('warning: docs/decisions.md:10 not a decision line, skipped: this line is junk')
        ->and($out)->toContain("warning: docs/ideas.md:11 unknown status 'maybe later', imported as proposed")
        ->and($out)->toContain('warning: docs/ideas.md:13 not a `| date | idea | status |` row, skipped')
        ->and($out)->toContain('possible duplicate: docs/ideas.md:12 "Workspace picks the theme" ~ docs/decisions.md:6 "The workspace picks the theme"');

    $superseding = importedAt($this->sandbox, 'docs/decisions.md', 5);
    expect($out)->toContain("warning: docs/decisions.md:5 mentions a supersession; link it by hand: kanban set {$superseding['id']} supersedes=+<ID>")
        ->and($superseding)->toMatchArray(['title' => 'Exports use their own theme',
            'body' => 'Supersedes the 2026-09-10 entry on the workspace theme.', 'why' => 'shared exports looked wrong outside the workspace.']);

    expect(importedAt($this->sandbox, 'docs/decisions.md', 6))->toMatchArray([
        'title' => 'The workspace picks the theme', 'body' => 'Why: first thought.', 'why' => 'the real reason, after the last marker.',
    ])->and(importedAt($this->sandbox, 'docs/decisions.md', 8))->toMatchArray([
        'title' => 'En dash and no why', 'body' => '', 'why' => '', 'decided_on' => '2026-09-01',
    ]);

    expect(importedAt($this->sandbox, 'docs/ideas.md', 7))->toMatchArray([
        'stage' => 'proposed', 'title' => 'Pipes in `a | b` code and | escaped', 'body' => 'Body text.', 'why' => 'tables.',
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 8))->toMatchArray([
        'stage' => 'decided', 'title' => 'A plain first sentence without bold that is the title', 'body' => 'More text follows here.',
        'decided_on' => '2026-09-19', 'resolution' => 'moved to decisions',
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 9))->toMatchArray([
        'stage' => 'decided', 'decided_on' => '2026-09-17', 'resolution' => null,
    ])->and(importedAt($this->sandbox, 'docs/ideas.md', 10))->toMatchArray([
        'stage' => 'dropped', 'resolution' => 'nobody needs it', 'why' => 'too costly.',
    ]);

    expect($this->sandbox->kanban('validate')->getExitCode())->toBe(0);
});

it('refuses junk lines under --strict and writes nothing', function () {
    houseDocs($this->sandbox, 'acme-decisions-edge.md', 'acme-ideas-edge.md');
    $commits = $this->sandbox->boardLog();

    $strict = $this->sandbox->kanban(['import-house-docs', '--strict']);

    expect($strict->getExitCode())->toBe(2)
        ->and($strict->getErrorOutput())->toContain('strict: 3 line(s) could not be parsed; nothing imported')
        ->and($strict->getErrorOutput())->toContain('docs/decisions.md:10')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(importedCards($this->sandbox))->toBe([]);

    houseDocs($this->sandbox);
    expect($this->sandbox->kanban(['import-house-docs', '--strict'])->getExitCode())->toBe(0);
});

it('creates a missing decisions board and refuses a work board', function () {
    houseDocs($this->sandbox);

    $refused = $this->sandbox->kanban(['import-house-docs', '--board=project/work']);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain('project/work is a work board');

    $out = $this->sandbox->ok(['import-house-docs', '--board=history/decisions']);
    $board = json_decode((string) file_get_contents($this->sandbox->root.'/docs/kanban/history/decisions/board.json'), true);
    expect($out)->toContain('created board history/decisions')
        ->and($board['kind'])->toBe('decisions')
        ->and(glob($this->sandbox->root.'/docs/kanban/history/decisions/ACME-*.json'))->toHaveCount(20);
});

it('skips a missing default file and fails on a missing explicit one', function () {
    houseDocs($this->sandbox);
    unlink($this->sandbox->root.'/docs/ideas.md');

    $missing = $this->sandbox->kanban(['import-house-docs', '--ideas=docs/nope.md']);
    expect($missing->getExitCode())->toBe(4)
        ->and($missing->getErrorOutput())->toContain('cannot read docs/nope.md');

    $out = $this->sandbox->ok('import-house-docs');
    expect($out)->toContain('skipped docs/ideas.md: not found')
        ->and($out)->toContain('imported 8: 8 decided, 0 proposed, 0 dropped (8 from docs/decisions.md)');
});

it('exits 0 with nothing to import when neither default file exists', function () {
    $run = $this->sandbox->kanban(['import-house-docs']);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe("nothing to import (no docs/decisions.md or docs/ideas.md)\n")
        ->and(glob($this->sandbox->root.'/docs/kanban/project/decisions/ACME-*.json'))->toBe([])
        ->and($this->sandbox->kanban(['import-house-docs', '--decisions=docs/decisions.md'])->getExitCode())->toBe(4);
});

it('refuses an entry whose date is not on the calendar and writes nothing', function () {
    @mkdir($this->sandbox->root.'/docs', 0775, true);
    file_put_contents($this->sandbox->root.'/docs/decisions.md', "# Decisions\n\n- **2026-13-45 — Use Postgres.** Everything lives there. Why: one engine.\n");
    $commits = $this->sandbox->boardLog();

    $import = $this->sandbox->kanban('import-house-docs');

    expect($import->getExitCode())->not->toBe(0)
        ->and($import->getErrorOutput())->toContain('docs/decisions.md:3')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(importedCards($this->sandbox))->toBe([])
        ->and($this->sandbox->kanban('validate')->getExitCode())->toBe(0);
});
