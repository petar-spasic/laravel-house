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
        ->and($s->boardLog()[0])->toBe("{$into} folded {$from} {$also} [owner]")
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('');

    $card = $s->read($into);
    expect($card)->toMatchArray(['stage' => 'ready', 'priority' => 'high',
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
        ->and($s->read($into))->toMatchArray(['stage' => 'backlog', 'blocked' => 'question: PDF or HTML?']);
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
        ->and($doing->getErrorOutput())->toContain("{$busy} is in doing: fold takes backlog and ready cards")
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
