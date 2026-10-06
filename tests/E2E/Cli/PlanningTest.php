<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

/** @return array<string, mixed> the card's last stage change */
function lastStageChange(array $card): array
{
    $changes = array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'stage'));

    return end($changes);
}

it('promotes a card to planning, and a plan of the owner\'s own moves it to ready', function () {
    $s = $this->sandbox;
    $id = $s->card('Export notes', ['--body=Build it', '--accept=It exports', '--accept=It is tested', '--label=area:export']);

    expect($s->ok(['promote', $id]))->toBe("promoted {$id} to planning\n");
    $out = $s->plan($id);

    $card = $s->read($id);
    $base = trim($s->git('rev-parse', 'main'));
    $planned = array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'planned'));
    expect($out)->toBe("planned {$id}: 9 lines, made on main @".substr($base, 0, 7)."; planning→ready\n")
        ->and($card['stage'])->toBe('ready')
        ->and($card['plan'])->toBe(trim(Sandbox::planFor([1, 2])))
        ->and($planned)->toHaveCount(1)
        ->and($planned[0])->toMatchArray(['base' => $base, 'by' => 'owner'])
        ->and(lastStageChange($card))->toMatchArray(['from' => 'planning', 'to' => 'ready', 'via' => 'plan']);
});

it('promotes a card whose plan still covers it straight to ready, and one that changed to planning', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Export notes');
    $s->ok(['move', $id, 'backlog']);

    expect($s->ok(['promote', $id]))->toBe("promoted {$id} to ready\n");

    $s->ok(['move', $id, 'backlog']);
    $s->ok(['set', $id, 'body=Build it as a zip']);

    expect($s->ok(['promote', $id]))->toBe("promoted {$id} to planning\n")
        ->and($s->read($id)['plan'])->toBe(trim(Sandbox::planFor([1])));
});

it('sends a ready card back to planning when its criteria or body change, and keeps it ready otherwise', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Export notes');

    expect($s->ok(['set', $id, 'priority=high', 'labels=+exports']))->toBe("{$id} updated\n")
        ->and($s->ok(['set', $id, 'body=Build  it']))->toBe("{$id} updated\n")
        ->and($s->read($id)['stage'])->toBe('ready');

    expect($s->ok(['set', $id, 'accept+=It is tested']))->toBe("{$id} updated, ready→planning: its plan does not cover the change\n")
        ->and(lastStageChange($s->read($id)))->toMatchArray(['from' => 'ready', 'to' => 'planning', 'via' => 'replan', 'reason' => 'its criteria or body changed since it was planned']);

    $s->plan($id);
    $s->ok(['set', $id, 'body=Build it, with a zip']);
    expect($s->read($id)['stage'])->toBe('planning');
});

it('moves ready cards without a current plan to planning on promote --auto, as an older board has them', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    $planned = $s->readyCard('Planned');
    $old = $s->card('Ready before planning existed', ['--body=Build it', '--accept=It works', '--label=area:old', '--stage=planning', '--priority=high']);
    $s->ok(['move', $old, 'ready', '--force'], $main);

    $refused = $s->kanban(['claim', $old], $main);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$old}: no current plan: `kanban promote --auto` sends it back to planning")
        ->and($s->ok(['next', '-v']))->toBe("{$planned} norm feature work Planned\nskipped {$old} no current plan: `kanban promote --auto` sends it back to planning\n");

    expect($s->ok(['promote', '--auto']))->toStartWith("replanned {$old}: no current plan\n")
        ->and($s->read($old)['stage'])->toBe('planning')
        ->and(lastStageChange($s->read($old)))->toMatchArray(['from' => 'ready', 'to' => 'planning', 'via' => 'replan', 'reason' => 'no current plan'])
        ->and($s->read($planned)['stage'])->toBe('ready');
});

it('shares max_parallel between workers and planners', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"max_parallel": 6', '"max_parallel": 2', file_get_contents($s->root.'/docs/kanban/kanban.json')));
    $s->ok(['claim', $s->readyCard('Doing')], $main);
    $planning = $s->card('Being planned', ['--body=x', '--accept=y', '--label=area:p1', '--stage=planning']);
    $s->ok(['claim', $planning], $main);
    $waits = $s->card('Waits for a planner', ['--body=x', '--accept=y', '--label=area:p2', '--stage=planning']);
    $s->readyCard('Waits for a worker');

    $refused = $s->kanban(['claim', $waits], $main);
    expect($s->ok('next'))->toBe("none: doing 1 + planning 1/2\n")
        ->and($s->ok(['next', '--planning']))->toBe("none: no capacity (doing 1 + planning 1/2)\n")
        ->and($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$waits}: no capacity (doing 1 + planning 1/2)")
        ->and($s->read($planning))->toMatchArray(['stage' => 'planning'])
        ->and($s->read($planning)['claim'])->not->toBeNull();
});

it('plans a card only once its dependencies are done; a plan of the owner\'s own skips that', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    $dep = $s->card('Shared contract');
    $id = $s->card('Uses the contract', ['--body=x', '--accept=y', '--label=area:uses', '--stage=planning', "--depends={$dep}"]);

    $refused = $s->kanban(['claim', $id], $main);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$id}: dependencies not done: {$dep}")
        ->and($s->ok(['next', '--planning']))->toBe("none: no plannable cards\nskipped {$id} waits on {$dep} (backlog)\n")
        ->and($s->ok('status'))->toContain("plan next: none: no plannable cards; waiting: {$id} waits on {$dep} (backlog)");

    $s->plan($id);
    expect($s->read($id)['stage'])->toBe('ready')
        ->and($s->ok('next'))->toBe("none: no startable ready cards\nskipped {$id} waits on {$dep} (backlog)\n");
});

it('takes a plan that pins a contract in a few lines of code, without a hint', function () {
    $s = $this->sandbox;
    $id = $s->card('Note versions', ['--body=x', '--accept=y', '--label=area:versions', '--stage=planning']);
    $plan = Sandbox::planFor([1])."\n## Facts\n- `note_versions` columns:\n```php\n\$table->id();\n\$table->foreignId('note_id')->constrained()->cascadeOnDelete();\n"
        ."\$table->unsignedInteger('version');\n\$table->longText('body');\n\$table->timestamp('saved_at');\n\$table->unique(['note_id', 'version']);\n```\n"
        ."- `curl -s -H 'Accept: application/json' http://acme.test/notes/1/versions | jq '.data[0] | {version, saved_at}'` lists the newest first\n";

    expect($s->ok(['plan', $id, '--plan-file=-'], [], $plan))->toStartWith("planned {$id}: ")->not->toContain('hint:')
        ->and($s->read($id))->toMatchArray(['stage' => 'ready', 'plan' => trim($plan)]);
});

it('refuses a plan of the owner\'s own for a card not waiting in planning, or with a planner\'s options', function () {
    $s = $this->sandbox;
    $backlog = $s->card('In the backlog', ['--body=x', '--accept=y', '--label=area:b']);
    $id = $s->card('Waits', ['--body=x', '--accept=y', '--label=area:w', '--stage=planning']);
    file_put_contents($s->root.'/notes.md', "untracked\n");

    $notPlanning = $s->kanban(['plan', $backlog, '--plan-file=-'], [], null, Sandbox::planFor([1]));
    $untracked = $s->kanban(['plan', $id, '--plan-file=-'], [], null, Sandbox::planFor([1], 'notes.md'));
    $note = $s->kanban(['plan', $id, '--plan-file=-', '--note=hi'], [], null, Sandbox::planFor([1]));
    $blocked = $s->kanban(['plan', $id, '--status=blocked']);

    expect($notPlanning->getExitCode())->toBe(3)
        ->and($notPlanning->getErrorOutput())->toContain("{$backlog} is backlog: a plan is given to a card waiting in planning")
        ->and($untracked->getExitCode())->toBe(2)
        ->and($untracked->getErrorOutput())->toContain('## Files: `notes.md` is tracked neither on main nor on the card\'s branch')
        ->and($note->getErrorOutput())->toContain("--note is for the card's planner")
        ->and($blocked->getErrorOutput())->toContain("a card that cannot be planned is blocked: `kanban set {$id} blocked=\"…\"`")
        ->and($s->read($id)['stage'])->toBe('planning');
});

it('plans a ready card again when the owner picks another option than its provisional decision took', function () {
    $s = $this->sandbox;
    $decision = "## Provisional decision\nWhich date an export file names?\nExample: an invoice from 3 March exported on 9 April.\n"
        ."1. Invoice date — files sort by billing period\n2. Export date — files sort by when they were made\nRecommended: 1 — billing periods\nTaken: 1";
    $id = $s->card('Export invoices', ['--body=Build it'."\n\n".$decision, '--accept=It exports', '--label=area:export', '--stage=planning']);
    $s->plan($id);
    $same = $s->card('Export receipts', ['--body=Build it'."\n\n".$decision, '--accept=It exports', '--label=area:receipts', '--stage=planning']);
    $s->plan($same);

    expect($s->ok(['answer', $same, '1']))->toBe("{$same}#1 answered: 1. Invoice date — files sort by billing period\n")
        ->and($s->read($same)['stage'])->toBe('ready');

    expect($s->ok(['answer', $id, '2']))->toBe("{$id}#1 answered: 2. Export date — files sort by when they were made\n"
        ."{$id}#1: the agent took 1; the card is planned again for 2 (ready→planning)\n");
    $card = $s->read($id);
    expect($card['stage'])->toBe('planning')
        ->and($s->ok(['show', $id]))->toContain(', no longer current')
        ->and(lastStageChange($card))->toMatchArray(['from' => 'ready', 'to' => 'planning', 'via' => 'replan', 'reason' => "the owner chose 2 for {$id}#1, not 1"]);
});

it('drops the plan of a card that is done or dropped, and keeps it in git', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Export notes');

    $s->ok(['move', $id, 'dropped', '--reason=not needed']);

    expect($s->read($id))->not->toHaveKey('plan')
        ->and($s->boardGit('log', '-p', '--format=', '-S', '## Steps', '--', "work/{$id}.json"))->toContain('"plan": "## Files');
});

/** A Provisional decision as a planner records it, its option 1 taken. */
function provisionalDecision(): string
{
    return "## Provisional decision\nWhich date an export file names?\nExample: an invoice from 3 March exported on 9 April.\n"
        ."1. Invoice date — files sort by billing period\n2. Export date — files sort by when they were made\nRecommended: 1 — billing periods\nTaken: 1";
}

it('plans a ready card again for text added after its questions or a note on an answer, never for a confirmation', function () {
    $s = $this->sandbox;
    $planned = fn (string $title, string $area) => tap($s->card($title, ['--body=Build it'."\n\n".provisionalDecision(), '--accept=It exports', "--label={$area}", '--stage=planning']), fn ($id) => $s->plan($id));
    $edited = $planned('Export invoices', 'area:invoices');
    $confirmed = $planned('Export receipts', 'area:receipts');
    $noted = $planned('Export credit notes', 'area:credits');

    $s->ok(['set', $edited, 'body=@-'], [], "Build it\n\n".provisionalDecision()."\nAlso: add a totals row.");
    $s->ok(['answer', $confirmed, '1']);
    $note = $s->ok(['answer', $noted, '1', '--note=Keep the old file names too.']);

    expect($s->read($edited)['stage'])->toBe('planning')
        ->and($s->read($confirmed)['stage'])->toBe('ready')
        ->and($s->read($noted)['stage'])->toBe('planning')
        ->and($note)->toContain("{$noted}: planned again with your note (ready→planning)");
});

it('takes a plan path with brackets, as a SvelteKit route file has', function () {
    $s = $this->sandbox;
    $id = $s->card('Invoice page', ['--body=x', '--accept=y', '--label=area:invoices', '--stage=planning']);
    $plan = str_replace('## Steps', "- create `frontend/src/routes/invoices/[id]/+page.svelte` — the invoice page\n\n## Steps", Sandbox::planFor([1]));

    expect($s->ok(['plan', $id, '--plan-file=-'], [], $plan))->toStartWith("planned {$id}: ");
});

it('plans one card ahead per area, and counts a planned card waiting on its area toward the buffer', function () {
    $s = $this->sandbox;
    $busy = $s->readyCard('Busy', ['--label=area:app']);
    $s->ok(['claim', $busy], ['KANBAN_SESSION' => 's1']);
    $first = $s->card('First', ['--body=x', '--accept=y', '--label=area:app', '--priority=high']);
    $second = $s->card('Second', ['--body=x', '--accept=y', '--label=area:app']);

    expect($s->ok(['promote', '--auto']))->toBe("promoted {$first} to planning\nskipped {$second}: area:app takes {$first} next (planning)\nready 0, planning 1: 1/12\n");

    $s->plan($first);

    expect($s->ok(['promote', '--auto']))->toBe("skipped {$second}: area:app takes {$first} next (ready)\nready 1, planning 0: 1/12\n");
});

it('leaves a planning card without a description to wait, naming why', function () {
    $s = $this->sandbox;
    $id = $s->card('Half written', ['--body=x', '--accept=y', '--label=area:half', '--stage=planning']);

    $s->ok(['set', $id, 'body=']);

    expect($s->ok(['next', '--planning']))->toBe("none: no plannable cards\nskipped {$id} R3 empty body\n");
});

it('lets workers and planners share the one place over capacity an urgent card may take', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"max_parallel": 6', '"max_parallel": 1', file_get_contents($s->root.'/docs/kanban/kanban.json')));
    $s->ok(['claim', $s->readyCard('Doing')], $main);
    $planning = $s->card('Urgent plan', ['--body=x', '--accept=y', '--label=area:urgent', '--priority=urgent', '--stage=planning']);
    $s->ok(['claim', $planning], $main);
    $s->readyCard('Urgent work', ['--priority=urgent']);

    expect($s->ok('next'))->toStartWith("none: doing 1 + planning 1/1\n");
});

it('sends a card stopped back to ready without a plan to planning, saying why', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    $id = $s->card('Older card', ['--body=x', '--accept=y', '--label=area:old', '--stage=planning']);
    $s->ok(['move', $id, 'ready', '--force'], $main);
    $s->ok(['claim', $id, '--force'], $main);

    expect($s->ok(['stop', $id, '--to=ready'], $main))->toContain("{$id} doing→planning: it has no plan; a planner plans it\n")
        ->and($s->read($id))->toMatchArray(['stage' => 'planning', 'claim' => null]);
});
