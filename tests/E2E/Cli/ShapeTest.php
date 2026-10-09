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

it('hints at a body sentence and a criterion over 25 words, and refuses neither', function () {
    $s = $this->sandbox;
    $long = 'The import should be refactored so that the parser handles a byte-order mark and Windows line endings properly and errors are shown to the user in a message.';
    $criterion = 'The import should be refactored so that the CSV parser handles UTF-8 BOM and Windows line endings properly and errors are shown to the user in a toast.';

    $out = $s->ok(['new', 'work', 'Import', '--body=Short first sentence. '.$long, '--accept='.$criterion, '--accept=An uploaded CSV imports all rows.']);
    expect($out)->toStartWith('created ')
        ->toContain("hint: the body has 1 sentence over 25 words: short sentences, one idea each (kanban skill, references/planning.md, \"The body and the criteria\")\n")
        ->toContain("hint: 1 criterion has over 25 words outside backticks: one outcome each (kanban skill, references/planning.md, \"The body and the criteria\")\n");

    $id = $s->card('Export', ['--body=Notes export as a file.', '--accept=The export downloads.']);
    $set = $s->ok(['set', $id, 'body=@-'], [], "{$long}\n\n- one item without a period\n- {$long}\n");
    expect($set)->toContain('hint: the body has 2 sentences over 25 words')->not->toContain('criterion has');
    expect($s->ok(['set', $id, 'accept+='.$criterion]))->toContain('hint: 1 criterion has over 25 words')->not->toContain('the body has');
    expect($s->ok(['set', $id, 'title=Export again']))->not->toContain('hint:');
});

it('counts no backticked name, code, table or generated section', function () {
    $s = $this->sandbox;
    $words = implode(' ', array_fill(0, 40, 'word'));
    $names = implode(' ', array_fill(0, 30, '`app/Http/Controllers/ImportController.php`'));
    $body = <<<MD
        ## Goal
        The owner imports notes from a CSV file
        - one list item without a period
        - another item, `config/kanban.php. ` with a dot inside a name
        Rows with errors show a message. {$names} stay exact.

        ```php
        {$words}
        ```

            {$words}

        | Column | {$words} |
        |---|---|

        ## Open question (2026-10-09)
        {$words}.
        Example: {$words}.
        1. One — {$words}
        2. Two — {$words}
        Recommended: 1 — {$words}

        ## Owner answer (2026-10-09)
        {$words}.

        ## Folded from ACME-B7Q2: Old card
        {$words}.

        ## Owner decision (D-1)
        {$words}.

        ## Notes
        The import keeps the original file.
        MD;

    $criterion = 'The import page shows each row of the uploaded file with its status after the owner uploads a CSV file on the page '
        .implode(' ', array_fill(0, 8, '`ImportTest.php`'));

    expect($s->ok(['new', 'work', 'Import', '--body='.$body, '--accept='.$criterion]))->not->toContain('hint:')
        ->and($s->ok(['new', 'work', 'Import more', '--body='.$body."\n{$words}."]))->toContain('hint: the body has 1 sentence over 25 words');
});
