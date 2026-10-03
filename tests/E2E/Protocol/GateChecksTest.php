<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
});

function stopWorker(ProtocolSandbox $p, string $wt): array
{
    return json_decode($p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $wt, 'agent' => 'a4d2c0ffee', 'type' => 'kanban-worker']))->getOutput(), true) ?? [];
}

function migration(ProtocolSandbox $p, string $cwd, string $name): void
{
    @mkdir($cwd.'/database/migrations', 0775, true);
    file_put_contents($cwd.'/database/migrations/'.$name, "<?php\n");
    $p->git($cwd, 'add', '-A');
    $p->git($cwd, 'commit', '-q', '-m', "migration {$name}");
}

it('refuses a review report whose branch holds a conflict marker, and keeps Markdown line breaks', function () {
    $this->p->commit($this->wt, 'notes.md', "Acme Notes  \nkeeps this line break\n", "{$this->id}: notes");
    $this->p->commit($this->wt, 'clauses.md', "intro\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> main\n", "{$this->id}: clauses");

    $report = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done']);
    $stop = stopWorker($this->p, $this->wt);

    expect($report->getOutput())->toContain('warning: Leftover conflict markers; resolve them and commit:')
        ->and($stop['decision'] ?? null)->toBe('block')
        ->and($stop['reason'])->toContain("clauses.md:2\nclauses.md:4\nclauses.md:6")->not->toContain('notes.md')
        ->and($this->p->card($this->id)['stage'])->toBe('doing');

    $this->p->commit($this->wt, 'clauses.md', "intro\nours and theirs\n", "{$this->id}: resolved");
    expect(stopWorker($this->p, $this->wt))->toBe([])
        ->and($this->p->card($this->id)['stage'])->toBe('review');
});

it('passes the migrations a branch adds with make:migration timestamps', function () {
    migration($this->p, $this->p->main, '2026_01_10_093015_create_notes_table.php');
    $this->p->git($this->wt, 'merge', '-q', 'main');
    migration($this->p, $this->wt, '2026_02_03_141522_add_title_to_notes_table.php');

    $run = $this->p->in($this->wt, ['migrations', '--base=main']);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe("migrations ok: 1 added\n");
});

it('refuses a migration timestamp that is round, shared or not newer than main', function (string $onMain, string $added, string $why) {
    migration($this->p, $this->p->main, $onMain);
    migration($this->p, $this->wt, $added);

    $run = $this->p->in($this->wt, ['migrations', '--base=main']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain("database/migrations/{$added}: timestamp ".substr($added, 0, 17)." {$why}")
        ->toContain('regenerate each with `php artisan make:migration`');
})->with([
    'round' => ['2026_01_10_093015_create_notes_table.php', '2026_02_03_140000_add_title_to_notes_table.php', 'ends in 0000'],
    'shared with main' => ['2026_01_10_093015_create_notes_table.php', '2026_01_10_093015_create_tags_table.php', 'is shared with another migration'],
    'older than main' => ['2026_03_01_080910_create_notes_table.php', '2026_02_03_141522_add_title_to_notes_table.php', "is not newer than main's newest migration (2026_03_01_080910)"],
]);

it('holds every reference-data id the branch started from, unless the branch adds a migration', function () {
    $this->p->commit($this->p->main, 'database/data/categories.json', json_encode([['id' => 'cat_0000000000000001', 'name' => 'Notes'], ['id' => 'cat_0000000000000002', 'name' => 'Lists']]), 'data');
    $this->p->commit($this->p->main, 'database/data/tags/urgent.json', json_encode(['id' => 'tag_0000000000000001', 'name' => 'Urgent']), 'data');
    [, $wt] = $this->p->started('Trim categories');

    $this->p->commit($wt, 'database/data/categories.json', json_encode([['id' => 'cat_0000000000000002', 'name' => 'Lists'], ['id' => 'cat_0000000000000003', 'name' => 'Boards']]), 'drop notes');
    $this->p->git($wt, 'mv', 'database/data/tags/urgent.json', 'database/data/tags/now.json');
    $this->p->git($wt, 'commit', '-q', '-m', 'rename the file, keep the id');
    $refused = $this->p->in($wt, ['data-ids', '--base=main']);

    expect($refused->getExitCode())->toBe(1)
        ->and($refused->getErrorOutput())->toContain('database/data/categories.json: id cat_0000000000000001 is gone')
        ->not->toContain('tag_0000000000000001');

    migration($this->p, $wt, '2026_09_30_142233_drop_notes_category.php');
    $passed = $this->p->in($wt, ['data-ids', '--base=main']);

    expect($passed->getExitCode())->toBe(0)
        ->and($passed->getOutput())->toBe("data ids: 1 removed or renamed, with a migration: database/migrations/2026_09_30_142233_drop_notes_category.php\n")
        ->and($this->p->in($this->wt, ['data-ids', '--base=main'])->getOutput())->toBe("data ids ok: 0 kept\n");
});

it('refuses two added migrations that share a timestamp', function () {
    migration($this->p, $this->wt, '2026_02_03_141522_create_tags_table.php');
    migration($this->p, $this->wt, '2026_02_03_141522_create_labels_table.php');

    $run = $this->p->in($this->wt, ['migrations', '--base=main']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain('create_labels_table.php: timestamp 2026_02_03_141522 is shared with another migration')
        ->toContain('create_tags_table.php: timestamp 2026_02_03_141522 is shared with another migration');
});

it('runs each gate under its own timeout, else gates.timeout', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->config(['gates' => ['timeout' => 1, 'report' => ['sleep 3']]]);

    $gates = $this->p->in($this->wt, ['gates']);
    $report = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done']);

    expect($gates->getExitCode())->toBe(1)
        ->and($gates->getOutput())->toBe("fail sleep 3 (timed out after 1 s)\n")
        ->and($report->getErrorOutput())->toContain('report not staged: Gate failed: `sleep 3` (timed out after 1 s)')
        ->and(glob($this->p->runtime('staged/*')))->toBe([]);

    $this->p->config(['gates' => ['timeout' => 1, 'report' => [['run' => 'sleep 3', 'timeout' => 10]]]]);
    expect($this->p->in($this->wt, ['gates'])->getOutput())->toBe("pass sleep 3 (exit 0)\n")
        ->and($this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->getExitCode())->toBe(0)
        ->and($this->p->in($this->wt, ['context'])->getOutput())->toContain("and `report` before it stages, up to 10 s):\n  sleep 3\n");
});

it('runs the gates when the report is staged, never again in the stop hook for the same head and gates', function () {
    $counter = $this->p->sandbox->root.'/../gate-runs-'.$this->id;
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->config(['gates' => ['report' => ['echo run >> '.escapeshellarg($counter)]]]);

    expect($this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->getExitCode())->toBe(0)
        ->and(file($counter))->toHaveCount(1)
        ->and(stopWorker($this->p, $this->wt))->toBe([])
        ->and(file($counter))->toHaveCount(1)
        ->and($this->p->card($this->id)['stage'])->toBe('review');
    @unlink($counter);
});

it('refuses a stop whose report predates a commit or a change of the gates', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->config(['gates' => ['report' => ['true']]]);
    $staged = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done']);
    $reported = substr(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')), 0, 7);
    $head = substr($this->p->commit($this->wt, 'more.php', "<?php\n", "{$this->id}: more"), 0, 7);

    expect($staged->getOutput())->toStartWith("gates passed (1)\n")
        ->and(stopWorker($this->p, $this->wt)['reason'])->toContain("Gates not proven: the gates passed at {$reported}, the branch is at {$head}.");

    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $this->p->config(['gates' => ['report' => ['true', 'true --again']]]);
    expect(stopWorker($this->p, $this->wt)['reason'])->toContain("Gates not proven: main's gates changed since they passed.")
        ->and($this->p->sandbox->ok(['apply']))->toContain("{$this->id}: report waits for live agent");
});

it('refuses a report when a gate writes into the worktree', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->config(['gates' => ['report' => ['echo formatted > app.php']]]);

    $report = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done']);

    expect($report->getExitCode())->not->toBe(0)
        ->and($report->getErrorOutput())->toContain('report not staged: a gate changed the worktree: gates must not write files')
        ->and(glob($this->p->runtime('staged/*')))->toBe([]);
});

it('runs every gate for the evaluator from the card worktree and names each failure', function () {
    $this->p->config(['gates' => ['report' => ["! git grep -n '<<<<<<<' -- ':!*.lock'", 'php -r "echo \'all good\';"']]]);
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");

    $pass = $this->p->in($this->wt, ['gates']);
    expect($pass->getExitCode())->toBe(0)
        ->and($pass->getOutput())->toBe("pass ! git grep -n '<<<<<<<' -- ':!*.lock' (exit 0)\npass php -r \"echo 'all good';\" (exit 0)\n");

    $this->p->commit($this->wt, 'clauses.md', "<<<<<<< HEAD\nours\n", "{$this->id}: marker");
    $fail = $this->p->in($this->wt, ['gates', $this->id]);
    expect($fail->getExitCode())->toBe(1)
        ->and($fail->getOutput())->toBe("fail ! git grep -n '<<<<<<<' -- ':!*.lock' (exit 1)\n  clauses.md:1:<<<<<<< HEAD\npass php -r \"echo 'all good';\" (exit 0)\n")
        ->and(glob($this->p->runtime('staged/*')))->toBe([]);

    $elsewhere = $this->p->sandbox->kanban(['gates', $this->id]);
    expect($elsewhere->getExitCode())->not->toBe(0)
        ->and($elsewhere->getErrorOutput())->toContain("gates runs from {$this->id}'s worktree");
});

it("says when the branch's config/kanban.php has other gates than main's", function () {
    $this->p->config(['gates' => ['report' => ['true']]]);
    @mkdir($this->wt.'/config');
    copy($this->p->main.'/config/kanban.php', $this->wt.'/config/kanban.php');
    expect($this->p->in($this->wt, ['context'])->getOutput())->not->toContain("this branch's config/kanban.php");

    file_put_contents($this->wt.'/config/kanban.php', "<?php\n// the branch's own\nreturn ['gates' => ['report' => ['true']]];\n");
    expect($this->p->in($this->wt, ['context'])->getOutput())->not->toContain("this branch's config/kanban.php");

    file_put_contents($this->wt.'/config/kanban.php', "<?php return ['gates' => ['report' => ['true', 'npm run check']]];\n");
    expect($this->p->in($this->wt, ['context'])->getOutput())
        ->toContain("  true\nthis branch's config/kanban.php has other gates than main's: main's apply; merge main if a gate needs code the branch lacks\n");
});

it('runs a gate with `when` only where its path exists', function () {
    $this->p->config(['gates' => ['report' => [['run' => 'false', 'when' => 'frontend/package.json']]]]);

    $without = $this->p->in($this->wt, ['gates']);
    @mkdir($this->wt.'/frontend');
    file_put_contents($this->wt.'/frontend/package.json', "{}\n");
    $with = $this->p->in($this->wt, ['gates']);

    expect($without->getExitCode())->toBe(0)
        ->and($without->getOutput())->toContain('pass false (skipped: no frontend/package.json)')
        ->and($with->getExitCode())->toBe(1)
        ->and($with->getOutput())->toContain('fail false (exit 1)');
});

it("lists the diff's new packages and its added TODOs, skipped tests and private addresses in context", function () {
    $this->p->commit($this->wt, 'composer.json', json_encode(['require' => ['php' => '^8.3']])."\n", "{$this->id}: composer");
    $this->p->commit($this->wt, 'composer.json', json_encode(['require' => ['php' => '^8.3', 'acme/widgets' => '^1.0']])."\n", "{$this->id}: a package");
    @mkdir($this->wt.'/frontend');
    $this->p->commit($this->wt, 'frontend/package.json', json_encode(['devDependencies' => ['left-pad' => '1.3.0']])."\n", "{$this->id}: npm");
    $this->p->commit($this->wt, 'tests/NotesTest.php', "<?php\nit('lists notes')->skip(); // TODO: write it\n\$host = '10.1.2.3';\n", "{$this->id}: test");
    $this->p->commit($this->wt, 'docs.md', "Example host 192.0.2.10\n", "{$this->id}: docs");

    $context = $this->p->in($this->wt, ['context'])->getOutput();

    expect($context)->toContain("new packages: acme/widgets (composer.json require), left-pad (frontend/package.json devDependencies)\n")
        ->toContain("added lines with TODO or FIXME: tests/NotesTest.php\n")
        ->toContain("added lines with a skipped test: tests/NotesTest.php\n")
        ->toContain("added lines with a private IPv4 address: tests/NotesTest.php\n")
        ->not->toContain('docs.md');
});

it("runs a kanban gate as main's binary, never the card's own copy, which its agent can change", function () {
    $marker = $this->p->sandbox->root.'/../tampered-'.$this->id;
    @mkdir($this->wt.'/vendor/bin', 0775, true);
    file_put_contents($this->wt.'/vendor/bin/kanban', "#!/bin/sh\ntouch {$marker}\n");
    chmod($this->wt.'/vendor/bin/kanban', 0755);
    $this->p->config(['gates' => ['report' => ['vendor/bin/kanban migrations --base=main']]]);

    $gates = $this->p->in($this->wt, ['gates']);

    expect($gates->getOutput())->toContain('pass vendor/bin/kanban migrations --base=main (exit 0)')
        ->and(file_exists($marker))->toBeFalse();
    @unlink($marker);
});
