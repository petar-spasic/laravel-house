<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('hints at the open card on an area when another joins it', function () {
    $s = $this->sandbox;
    $first = $s->card('Export notes', ['--label=area:export']);

    $out = $s->ok(['new', 'work', 'Export tags', '--label=area:export']);
    preg_match('/^created (\S+)/', $out, $m);
    expect($out)->toContain("hint: area:export already has an open card, {$first} Export notes; extend it, or fold this one into it: kanban fold {$m[1]} --into={$first}");

    $third = $s->card('Fonts', ['--label=area:fonts']);
    $set = $s->kanban(['set', $third, 'labels=+area:export']);
    expect($set->getExitCode())->toBe(0)
        ->and($set->getOutput())->toContain("hint: area:export already has an open card, {$first} Export notes (and 1 more)")
        ->and($s->ok(['set', $third, 'title=Fonts again']))->not->toContain('hint:')
        ->and($s->ok(['new', 'work', 'Alone', '--label=area:alone']))->not->toContain('hint:');
});

it('hints at a hub and lists it in the brief', function () {
    $s = $this->sandbox;
    $hub = $s->card('Shared schema');
    $one = $s->card('One', ['--depends='.$hub]);
    $two = $s->card('Two', ['--depends='.$hub]);
    expect($s->ok('status'))->not->toContain('hubs:');

    $three = $s->kanban(['new', 'work', 'Three', '--depends='.$hub]);
    expect($three->getExitCode())->toBe(0)
        ->and($three->getOutput())->toContain("hint: {$hub} blocks 3 open cards (")->toContain($one)->toContain($two)
        ->and($three->getOutput())->toMatch("/; if they are one piece of work: kanban fold ACME-\\w+ ACME-\\w+ ACME-\\w+ --into={$hub}\\n/");

    $four = $s->card('Four');
    $set = $s->kanban(['set', $four, 'depends_on=+'.$hub]);
    expect($set->getExitCode())->toBe(0)
        ->and($set->getOutput())->toContain("hint: {$hub} blocks 4 open cards")
        ->and($s->ok('status'))->toContain("hubs: {$hub} blocks 4");
});
