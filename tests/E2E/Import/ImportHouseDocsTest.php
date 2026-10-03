<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

function houseDocs(Sandbox $sandbox, string $decisions = 'acme-decisions.md', string $ideas = 'acme-ideas.md'): void
{
    @mkdir($sandbox->root.'/docs', 0775, true);
    $fixtures = dirname(__DIR__, 2).'/Support/fixtures/import';
    copy("{$fixtures}/{$decisions}", $sandbox->root.'/docs/decisions.md');
    copy("{$fixtures}/{$ideas}", $sandbox->root.'/docs/ideas.md');
}

function archived(Sandbox $sandbox): string
{
    return (string) @file_get_contents($sandbox->root.'/docs/kanban/decisions.md');
}

/** @return array<string, string> source "file:line" => the archive section imported from it */
function archivedSections(Sandbox $sandbox): array
{
    $sections = [];
    foreach (preg_split('/^(?=### )/m', archived($sandbox)) as $chunk) {
        if (preg_match('/^\*\d{4}-\d{2}-\d{2} · \w+ · (\S+)\*$/m', $chunk, $m) === 1) {
            $sections[$m[1]] = trim($chunk);
        }
    }

    return $sections;
}

/** @return array<string, array<string, mixed>> source "file:line" => the spike imported from it */
function importedSpikes(Sandbox $sandbox): array
{
    $spikes = [];
    foreach (glob($sandbox->root.'/docs/kanban/project/work/*-*.json') as $file) {
        $card = json_decode((string) file_get_contents($file), true);
        $spikes[$card['log'][0]['source']] = $card;
    }
    ksort($spikes);

    return $spikes;
}

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('archives what was decided or dropped and makes each floated idea a backlog spike, in one commit', function () {
    houseDocs($this->sandbox);
    $commits = count($this->sandbox->boardLog());

    $out = $this->sandbox->ok('import-house-docs');

    expect($out)->toContain('imported 20: 10 archived (9 decided, 1 dropped), 10 backlog spikes with an open question (8 from docs/decisions.md, 12 from docs/ideas.md); 0 already imported')
        ->and($out)->not->toContain('warning:')
        ->and(count($this->sandbox->boardLog()))->toBe($commits + 1)
        ->and($this->sandbox->boardLog()[0])->toBe('Kanban: import house docs (10 archived, 10 spikes) [owner]')
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and(archivedSections($this->sandbox))->toHaveCount(10)
        ->and(importedSpikes($this->sandbox))->toHaveCount(10)
        ->and(glob($this->sandbox->root.'/docs/kanban/*/*/*.json'))->toHaveCount(11);

    $line = file($this->sandbox->root.'/docs/decisions.md', FILE_IGNORE_NEW_LINES)[7];
    $search = archivedSections($this->sandbox)['docs/decisions.md:8'];
    expect($search)->toStartWith('### ACME-')
        ->toContain(" — Search is a Postgres full-text index, not a separate engine\n\n*2026-09-27 · decided · docs/decisions.md:8*\n\n"
            ."Titles weigh above bodies (`setweight` A and B) and results never cross workspaces.\n\n"
            .'**Why:** one datastore to run, and the volumes stay far below where it stops being enough.')
        ->and($out)->toMatch('/^archived ACME-[0-9A-Z]{6} decided docs\/decisions\.md:8 Search is a Postgres/m')
        ->and($line)->toContain('Search is a Postgres');

    expect(archivedSections($this->sandbox)['docs/ideas.md:16'])
        ->toContain('*2026-09-27 · decided · docs/ideas.md:16*')->toContain('**Resolution:** (`decisions.md`, search is a Postgres index)')
        ->and(archivedSections($this->sandbox)['docs/ideas.md:17'])
        ->toContain("— Built-in video calls\n\n*2026-09-19 · dropped · docs/ideas.md:17*")->toContain('**Resolution:** out of scope for a note app');

    // sorted by date, oldest first
    preg_match_all('/^\*(\d{4}-\d{2}-\d{2})/m', archived($this->sandbox), $dates);
    $sorted = $dates[1];
    sort($sorted);
    expect(archived($this->sandbox))->toStartWith("# Decisions\n")
        ->and($dates[1])->toBe($sorted);

    $offline = importedSpikes($this->sandbox)['docs/ideas.md:8'];
    expect($offline)->toMatchArray([
        'type' => 'spike', 'stage' => 'backlog', 'title' => 'Offline-first editing', 'blocked' => 'question: Offline-first editing',
        'created' => '2026-09-27T00:00:00.000+00:00', 'acceptance' => [], 'labels' => [],
    ])->and($offline['body'])->toStartWith("## Open question\n\nThe web app keeps a local copy")
        ->toContain("\n\nWhy: most notes are written on trains and planes.\n\nFloated on 2026-09-27 (docs/ideas.md:8).")
        ->and($offline['log'][0])->toMatchArray(['by' => 'import', 'event' => 'created', 'hash' => sha1(file($this->sandbox->root.'/docs/ideas.md', FILE_IGNORE_NEW_LINES)[7])])
        ->and($out)->toContain("created {$offline['id']} backlog docs/ideas.md:8 Offline-first editing");

    expect($this->sandbox->kanban('validate')->getExitCode())->toBe(0)
        ->and($this->sandbox->kanban(['promote', $offline['id']])->getExitCode())->not->toBe(0);
});

it('removes the imported files once every entry is on the board, and names what still points at them', function () {
    houseDocs($this->sandbox);
    file_put_contents($this->sandbox->root.'/CLAUDE.md', "# Acme Notes\n\nRead docs/decisions.md first.\n");
    $this->sandbox->git('add', 'CLAUDE.md');

    $out = $this->sandbox->ok(['import-house-docs', '--remove-sources']);

    expect($out)->toContain("removed docs/decisions.md\nremoved docs/ideas.md\nstill points at it: CLAUDE.md:3:Read docs/decisions.md first.\nnext: commit the removal on main\n")
        ->and(file_exists($this->sandbox->root.'/docs/decisions.md'))->toBeFalse()
        ->and(file_exists($this->sandbox->root.'/docs/ideas.md'))->toBeFalse()
        ->and(archivedSections($this->sandbox))->toHaveCount(10);
});

it('keeps the files when a line warned', function () {
    houseDocs($this->sandbox, 'acme-decisions-edge.md', 'acme-ideas-edge.md');

    $run = $this->sandbox->kanban(['import-house-docs', '--remove-sources']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toMatch('/kept docs\/decisions\.md, docs\/ideas\.md: \d+ warning\(s\) above/')
        ->and(file_exists($this->sandbox->root.'/docs/decisions.md'))->toBeTrue();
});

it('writes nothing on a dry run and lists what it would import', function () {
    houseDocs($this->sandbox);
    $commits = $this->sandbox->boardLog();

    $out = $this->sandbox->ok(['import-house-docs', '--dry-run']);

    expect($out)->toMatch('/^docs\/decisions\.md:7 decided 2026-09-28 A workspace has exactly one owner → archive ACME-[0-9A-Z]{6}$/m')
        ->and($out)->toContain("docs/ideas.md:8 proposed 2026-09-27 Offline-first editing → backlog spike on project/work\n")
        ->and($out)->toContain('would import 20: 10 archived (9 decided, 1 dropped), 10 backlog spikes')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(archived($this->sandbox))->toBe('')
        ->and(importedSpikes($this->sandbox))->toBe([])
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');
});

it('changes nothing on a re-run, and adds only what is new', function () {
    houseDocs($this->sandbox);
    $this->sandbox->ok('import-house-docs');
    $commits = $this->sandbox->boardLog();
    $archive = archived($this->sandbox);

    $again = $this->sandbox->ok('import-house-docs');

    expect($again)->toContain('imported 0: 0 archived (0 decided, 0 dropped), 0 backlog spikes with an open question; 20 already imported')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(archived($this->sandbox))->toBe($archive)
        ->and(importedSpikes($this->sandbox))->toHaveCount(10)
        ->and($this->sandbox->ok(['import-house-docs', '--dry-run']))->toContain('would import 0:');

    file_put_contents($this->sandbox->root.'/docs/decisions.md', "- **2026-10-01 — Tags are lowercase.** Why: one spelling.\n", FILE_APPEND);
    expect($this->sandbox->ok('import-house-docs'))->toContain('imported 1: 1 archived (1 decided, 0 dropped), 0 backlog spikes')
        ->and(archived($this->sandbox))->toStartWith(substr($archive, 0, -1))
        ->and(archived($this->sandbox))->toEndWith("**Why:** one spelling.\n");
});

it('parses the edge cases and warns about what needs a hand', function () {
    houseDocs($this->sandbox, 'acme-decisions-edge.md', 'acme-ideas-edge.md');

    $out = $this->sandbox->ok('import-house-docs');

    expect($out)->toContain('imported 10: 7 archived (6 decided, 1 dropped), 3 backlog spikes with an open question (4 from docs/decisions.md, 6 from docs/ideas.md)')
        ->and($out)->toContain('warning: docs/decisions.md:7 bold paragraph instead of a bullet, imported as decided')
        ->and($out)->toContain('warning: docs/decisions.md:9 not a decision line, skipped: ## Older')
        ->and($out)->toContain('warning: docs/decisions.md:10 not a decision line, skipped: this line is junk')
        ->and($out)->toContain("warning: docs/ideas.md:11 unknown status 'maybe later', imported as proposed")
        ->and($out)->toContain('warning: docs/ideas.md:13 not a `| date | idea | status |` row, skipped')
        ->and($out)->toContain('possible duplicate: docs/ideas.md:12 "Workspace picks the theme" ~ docs/decisions.md:6 "The workspace picks the theme"');

    $sections = archivedSections($this->sandbox);
    expect($sections['docs/decisions.md:5'])->toContain(" — Exports use their own theme\n")
        ->toContain("Supersedes the 2026-09-10 entry on the workspace theme.\n\n**Why:** shared exports looked wrong outside the workspace.")
        ->and($sections['docs/decisions.md:6'])->toContain(" — The workspace picks the theme\n")->toContain("Why: first thought.\n\n**Why:** the real reason, after the last marker.")
        ->and($sections['docs/decisions.md:8'])->toEndWith(" — En dash and no why\n\n*2026-09-01 · decided · docs/decisions.md:8*")
        ->and($sections['docs/ideas.md:8'])->toContain(" — A plain first sentence without bold that is the title\n\n*2026-09-19 · decided · docs/ideas.md:8*\n\nMore text follows here.")
        ->toContain('**Resolution:** moved to decisions')
        ->and($sections['docs/ideas.md:9'])->toContain('*2026-09-17 · decided · docs/ideas.md:9*')->not->toContain('Resolution')
        ->and($sections['docs/ideas.md:10'])->toContain('**Why:** too costly.')->toContain('**Resolution:** nobody needs it');

    $spikes = importedSpikes($this->sandbox);
    expect(array_keys($spikes))->toBe(['docs/ideas.md:11', 'docs/ideas.md:12', 'docs/ideas.md:7'])
        ->and($spikes['docs/ideas.md:7'])->toMatchArray(['title' => 'Pipes in `a | b` code and | escaped', 'blocked' => 'question: Pipes in `a | b` code and | escaped'])
        ->and($spikes['docs/ideas.md:7']['body'])->toBe("## Open question\n\nBody text.\n\nWhy: tables.\n\nFloated on 2026-09-20 (docs/ideas.md:7).");

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
        ->and(archived($this->sandbox))->toBe('');

    houseDocs($this->sandbox);
    expect($this->sandbox->kanban(['import-house-docs', '--strict'])->getExitCode())->toBe(0);
});

it('skips a missing default file and fails on a missing explicit one', function () {
    houseDocs($this->sandbox);
    unlink($this->sandbox->root.'/docs/ideas.md');

    $missing = $this->sandbox->kanban(['import-house-docs', '--ideas=docs/nope.md']);
    expect($missing->getExitCode())->toBe(4)
        ->and($missing->getErrorOutput())->toContain('cannot read docs/nope.md');

    $out = $this->sandbox->ok('import-house-docs');
    expect($out)->toContain('skipped docs/ideas.md: not found')
        ->and($out)->toContain('imported 8: 8 archived (8 decided, 0 dropped), 0 backlog spikes with an open question (8 from docs/decisions.md)');
});

it('exits 0 with nothing to import when neither default file exists', function () {
    $run = $this->sandbox->kanban(['import-house-docs']);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe("nothing to import (no docs/decisions.md or docs/ideas.md)\n")
        ->and(archived($this->sandbox))->toBe('')
        ->and($this->sandbox->kanban(['import-house-docs', '--decisions=docs/decisions.md'])->getExitCode())->toBe(4);
});

it('refuses an entry whose date is not on the calendar and writes nothing', function () {
    @mkdir($this->sandbox->root.'/docs', 0775, true);
    file_put_contents($this->sandbox->root.'/docs/decisions.md', "# Decisions\n\n- **2026-13-45 — Use Postgres.** Everything lives there. Why: one engine.\n");
    $commits = $this->sandbox->boardLog();

    $import = $this->sandbox->kanban('import-house-docs');

    expect($import->getExitCode())->not->toBe(0)
        ->and($import->getErrorOutput())->toContain('docs/decisions.md:3: 2026-13-45 is not a date on the calendar')
        ->and($this->sandbox->boardLog())->toBe($commits)
        ->and(archived($this->sandbox))->toBe('')
        ->and($this->sandbox->kanban('validate')->getExitCode())->toBe(0);
});
