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

it('refuses two added migrations that share a timestamp', function () {
    migration($this->p, $this->wt, '2026_02_03_141522_create_tags_table.php');
    migration($this->p, $this->wt, '2026_02_03_141522_create_labels_table.php');

    $run = $this->p->in($this->wt, ['migrations', '--base=main']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain('create_labels_table.php: timestamp 2026_02_03_141522 is shared with another migration')
        ->toContain('create_tags_table.php: timestamp 2026_02_03_141522 is shared with another migration');
});
