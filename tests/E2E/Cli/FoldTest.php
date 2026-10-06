<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('folds cards into one in one commit and repoints their dependents', function () {
    $s = $this->sandbox;
    $base = $s->card('Storage');
    $other = $s->card('Fonts');
    $into = $s->readyCard('Export notes', ['--label=area:export', '--label=pdf', '--accept=Exports one note', '--depends='.$base]);
    $from = $s->card('Export attachments', ['--priority=high', '--label=area:export', '--label=files', '--body=Attachments too',
        '--accept=Exports attachments', '--accept=Keeps file names', '--depends='.$other, '--depends='.$into]);
    $also = $s->card('Export tags', ['--label=area:tags', '--accept=Exports tags', '--depends='.$from]);
    $dependent = $s->card('Share exports', ['--depends='.$from, '--depends='.$into]);

    $out = $s->ok(['fold', $from, strtolower(substr($also, 5)), "--into={$into}"]);

    expect($out)->toStartWith("{$into} folded {$from}, {$also}: 3 criteria added; repointed to it: {$dependent}\n")
        ->and($out)->toContain("{$into} goes back to planning: its plan does not cover what was folded in")
        ->and($s->boardLog()[0])->toBe("{$into} folded {$from} {$also} [owner]")
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('');

    $card = $s->read($into);
    expect($card)->toMatchArray(['stage' => 'planning', 'priority' => 'high',
        'labels' => ['area:export', 'area:tags', 'files', 'pdf'],
        'depends_on' => collect([$base, $other])->sort()->values()->all(),
        'body' => "Build it\n\n## Folded from {$from}: Export attachments\n\nAttachments too\n\n## Folded from {$also}: Export tags\n"])
        ->and(array_column($card['acceptance'], 'text'))->toBe(['It works', 'Exports one note', 'Exports attachments', 'Keeps file names', 'Exports tags'])
        ->and(array_column($card['acceptance'], 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($card['log'], 'event'))->toContain('folded');

    foreach ([$from, $also] as $id) {
        expect($s->read($id)['stage'])->toBe('dropped')
            ->and(end($s->read($id)['log']))->toMatchArray(['event' => 'stage', 'to' => 'dropped', 'via' => 'fold', 'reason' => "folded into {$into}"])
            ->and($s->ok(['show', $id]))->toContain('dropped');
    }
    expect($s->read($dependent)['depends_on'])->toBe([$into]);
});

it('sends a ready card back to backlog when it takes an open question', function () {
    $s = $this->sandbox;
    $into = $s->readyCard('Export notes');
    $from = $s->card('Export format');
    $s->ok(['set', $from, 'blocked=question: PDF or HTML?']);

    $out = $s->ok(['fold', $from, "--into={$into}"]);

    expect($out)->toContain("{$into} goes back to backlog: it carries an open question")
        ->and($s->read($into))->toMatchArray(['stage' => 'backlog', 'blocked' => 'question: PDF or HTML?'])
        ->and($s->read($from))->toMatchArray(['stage' => 'dropped', 'blocked' => null])
        ->and($s->ok(['status']))->toContain('blocked 1 · questions 1 open');
});

it('keeps one copy of a question the folded cards share, answered once', function () {
    $s = $this->sandbox;
    $question = "Should a seat limit block invites?\n1. Block — invites wait for a seat\n2. Warn — invites go out\nRecommended: 2 — growth";
    $into = $s->card('Seats', ['--label=area:seats', "--body=Seats.\n\n## Open question (2026-10-01)\n{$question}"]);
    $s->ok(['set', $into, 'blocked=question: Should a seat limit block invites?']);
    $from = array_map(fn (string $day) => $s->card("Seat part {$day}", ['--label=area:seats', "--body=Part.\n\n## Open question (2026-10-{$day})\n{$question}"]), ['02', '03']);

    $s->ok(['fold', ...$from, "--into={$into}"]);

    expect(substr_count($s->read($into)['body'], '## Open question'))->toBe(1)
        ->and($s->read($into)['body'])->toContain("## Folded from {$from[0]}: Seat part 02\n\nPart.\n")
        ->and($s->ok('questions'))->toContain("{$into}#1 open question: Seats\n");

    $s->ok(['answer', $into, '2']);
    expect($s->read($into)['blocked'])->toBeNull()
        ->and($s->ok('questions'))->toBe("no open questions\n");
});

it('takes no block for a question the target has already answered', function () {
    $s = $this->sandbox;
    $question = "Should a seat limit block invites?\n1. Block — invites wait for a seat\n2. Warn — invites go out\nRecommended: 2 — growth";
    $into = $s->card('Seats', ['--label=area:seats', "--body=Seats.\n\n## Open question (2026-10-01)\n{$question}"]);
    $s->ok(['set', $into, 'blocked=question: Should a seat limit block invites?']);
    $s->ok(['answer', $into, '2']);
    $from = $s->card('Seat part', ['--label=area:seats', "--body=Part.\n\n## Open question (2026-10-02)\n{$question}"]);
    $s->ok(['set', $from, 'blocked=question: Should a seat limit block invites?']);

    $s->ok(['fold', $from, "--into={$into}"]);

    expect($s->read($into)['blocked'])->toBeNull()
        ->and($s->ok('questions'))->toBe("no open questions\n");
});

it('refuses a fold past the card limits and writes nothing', function () {
    $s = $this->sandbox;
    $into = $s->card('Big', array_map(fn (int $i) => "--accept=Criterion {$i}", range(1, 20)));
    $from = $s->card('Bigger', array_map(fn (int $i) => "--accept=More {$i}", range(1, 5)));
    $head = $s->boardGit('rev-parse', 'HEAD');

    $refused = $s->kanban(['fold', $from, "--into={$into}"]);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused: {$into} would have 25 acceptance criteria (at most 24)")
        ->and($s->boardGit('rev-parse', 'HEAD'))->toBe($head)
        ->and($s->read($from)['stage'])->toBe('backlog');
});

it('refuses cards outside backlog and ready, a locked dependent, and a card into itself', function () {
    $s = $this->sandbox;
    $into = $s->card('Keeper');
    $busy = $s->readyCard('Busy');
    $s->ok(['claim', $busy], ['KANBAN_SESSION' => 's1']);
    $gone = $s->card('Gone');
    $s->ok(['move', $gone, 'dropped', '--reason=not needed']);

    $doing = $s->kanban(['fold', $busy, "--into={$into}"]);
    expect($doing->getExitCode())->toBe(3)
        ->and($doing->getErrorOutput())->toContain("{$busy} is in doing: fold takes backlog, planning and ready cards no agent holds")
        ->and($s->kanban(['fold', $gone, "--into={$into}"])->getExitCode())->toBe(3)
        ->and($s->kanban(['fold', $into, "--into={$into}"])->getExitCode())->toBe(2)
        ->and($s->kanban(['fold', $into])->getExitCode())->toBe(2);

    $from = $s->card('Folded');
    $waiting = $s->readyCard('Waits', ['--depends='.$from]);
    $s->ok(['claim', $waiting, '--force'], ['KANBAN_SESSION' => 's1']);
    $locked = $s->kanban(['fold', $from, "--into={$into}"]);
    expect($locked->getExitCode())->toBe(3)
        ->and($locked->getErrorOutput())->toContain("{$waiting} is in doing, a locked stage, and depends on {$from}")
        ->and($s->read($from)['stage'])->toBe('backlog');
});

it('refuses a fold that would close a dependency cycle', function () {
    $s = $this->sandbox;
    $from = $s->card('Schema');
    $middle = $s->card('Migrations', ['--depends='.$from]);
    $into = $s->card('Models', ['--depends='.$middle]);
    $head = $s->boardGit('rev-parse', 'HEAD');

    $refused = $s->kanban(['fold', $from, "--into={$into}"]);

    expect($refused->getExitCode())->toBe(2)
        ->and($refused->getErrorOutput())->toContain('dependency cycle')
        ->and($s->boardGit('rev-parse', 'HEAD'))->toBe($head);
});

it('gives the card the epic of the folded cards when it has none, and says so when they disagree', function () {
    $s = $this->sandbox;
    $s->ok(['epic', 'exports', 'Exports']);
    $s->ok(['epic', 'sharing', 'Sharing']);
    $into = $s->card('Export notes');
    $from = $s->card('Export tags', ['--epic=exports']);

    $s->ok(['fold', $from, "--into={$into}"]);
    expect($s->read($into)['epic'])->toBe('exports');

    $other = $s->card('Share a link', ['--epic=sharing']);
    expect($s->ok(['fold', $other, "--into={$into}"]))->toContain("{$into} keeps the epic exports; the folded cards had sharing: set epic= if it belongs elsewhere")
        ->and($s->read($into)['epic'])->toBe('exports');
});
