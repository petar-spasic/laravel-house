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
        ->and($untracked->getErrorOutput())->toContain('## Files: `notes.md` is not in the commit the plan is made on')
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
        ->and($card)->not->toHaveKey('plan')
        ->and(lastStageChange($card))->toMatchArray(['from' => 'ready', 'to' => 'planning', 'via' => 'replan', 'reason' => "the owner chose 2 for {$id}#1, not 1"]);
});

it('drops the plan of a card that is done or dropped, and keeps it in git', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Export notes');

    $s->ok(['move', $id, 'dropped', '--reason=not needed']);

    expect($s->read($id))->not->toHaveKey('plan')
        ->and($s->boardGit('log', '-p', '--format=', '-S', '## Steps', '--', "work/{$id}.json"))->toContain('"plan": "## Files');
});
