<?php

use PetarSpasic\Kanban\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('creates a card as one canonical file and one commit', function () {
    $id = $this->sandbox->card('Conditional clauses', ['--type=bug', '--priority=high', '--label=area:engine', '--accept=Renders the page', '--body=## Context']);

    expect($id)->toMatch('/^ACME-[0-9A-HJKMNP-TV-Z]{6}$/')
        ->and($this->sandbox->boardLog()[0])->toBe("{$id} created [owner]")
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');

    $file = file_get_contents($this->sandbox->root."/docs/kanban/project/work/{$id}.json");
    $card = json_decode($file, true);
    expect(array_keys($card))->toBe(['id', 'type', 'title', 'stage', 'priority', 'labels', 'body', 'acceptance', 'depends_on', 'blocked', 'claim', 'work', 'created', 'updated', 'log'])
        ->and($card)->toMatchArray(['type' => 'bug', 'stage' => 'backlog', 'priority' => 'high', 'labels' => ['area:engine'],
            'acceptance' => [['id' => 1, 'text' => 'Renders the page', 'done' => false]], 'claim' => null])
        ->and($card['created'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00$/')
        ->and($card['log'][0])->toMatchArray(['by' => 'owner', 'event' => 'created'])
        ->and($file)->toEndWith("}\n")
        ->and($file)->toContain("\n    \"id\": ");
});

it('shows a card by full id, lowercase prefix and Crockford aliases', function () {
    $env = ['KANBAN_ID_SEQUENCE' => 'K1N0PQ,K1N7AB,Z0Z0Z0'];
    $first = $this->sandbox->card('First', env: $env);
    $second = $this->sandbox->card('Second', env: $env);

    expect([$first, $second])->toBe(['ACME-K1N0PQ', 'ACME-K1N7AB'])
        ->and($this->sandbox->ok(['show', 'ACME-K1N0PQ']))->toStartWith("ACME-K1N0PQ First\n")
        ->and($this->sandbox->ok(['show', 'kin0']))->toStartWith("ACME-K1N0PQ First\n")
        ->and($this->sandbox->ok(['show', 'acme-k1n7']))->toStartWith("ACME-K1N7AB Second\n");

    $ambiguous = $this->sandbox->kanban(['show', 'K1N']);
    expect($ambiguous->getExitCode())->toBe(4)
        ->and($ambiguous->getErrorOutput())->toContain("'K1N' is ambiguous: ACME-K1N0PQ, ACME-K1N7AB");

    expect($this->sandbox->kanban(['show', 'K1'])->getExitCode())->toBe(4)
        ->and($this->sandbox->kanban(['show', 'ACME-ZZZZZZ'])->getExitCode())->toBe(4);
});

it('lists ready, doing, review and blocked by default, everything with --all', function () {
    $backlog = $this->sandbox->card('Parked idea');
    $blocked = $this->sandbox->card('Waiting on owner');
    $this->sandbox->ok(['set', $blocked, 'blocked=needs a decision']);
    $ready = $this->sandbox->readyCard('Next up', ['--priority=high']);
    $decision = $this->sandbox->card('Workspace per team', board: 'project/decisions');

    $default = $this->sandbox->ok('list');
    expect($default)->toBe(implode("\n", [
        "{$ready} ready high feature project/work Next up",
        "{$blocked} backlog normal feature project/work Waiting on owner [blocked: needs a decision]",
    ])."\n");

    $all = $this->sandbox->ok(['list', '--all']);
    expect($all)->toContain($backlog)->toContain($decision);

    $json = json_decode($this->sandbox->ok(['list', '--stage=proposed', '--json']), true);
    expect($json)->toHaveCount(1)
        ->and($json[0])->toMatchArray(['id' => $decision, 'type' => 'decision', 'stage' => 'proposed', 'board' => 'project/decisions']);

    expect($this->sandbox->ok(['list', '--board=project/work', '--label=nope']))->toBe("no cards\n");
});

it('sets fields with the key=value syntax', function () {
    $dep = $this->sandbox->card('Dependency');
    $id = $this->sandbox->card('Editable', ['--accept=First', '--label=ui']);

    $this->sandbox->ok(['set', $id, 'priority=urgent', 'labels=+area:pdf,-ui', 'depends_on=+'.strtolower(substr($dep, 5, 4)),
        'accept+=Second', 'accept[1]=First, reworded', 'tick=2', 'body=@-', 'note=Talked to the owner'], input: "## Build\nThe thing\n");

    $card = $this->sandbox->read($id);
    expect($card)->toMatchArray([
        'priority' => 'urgent',
        'labels' => ['area:pdf'],
        'depends_on' => [$dep],
        'body' => "## Build\nThe thing\n",
        'acceptance' => [['id' => 1, 'text' => 'First, reworded', 'done' => false], ['id' => 2, 'text' => 'Second', 'done' => true]],
    ]);
    $log = array_column($card['log'], null, 'event');
    expect(array_keys($log))->toEqualCanonicalizing(['created', 'note', 'set'])
        ->and($log['set']['fields'])->toBe(['acceptance', 'body', 'depends_on', 'labels', 'priority'])
        ->and($log['note']['text'])->toBe('Talked to the owner')
        ->and($this->sandbox->boardLog()[0])->toBe("{$id} note; set acceptance,body,depends_on,labels,priority [owner]");

    $this->sandbox->ok(['set', $id, 'accept-=1']);
    $this->sandbox->ok(['set', $id, 'accept+=Third']);
    expect(array_column($this->sandbox->read($id)['acceptance'], 'id'))->toBe([2, 3]);

    $this->sandbox->ok(['set', $id, 'blocked=Waiting']);
    expect($this->sandbox->read($id)['blocked'])->toBe('Waiting');
    $this->sandbox->ok(['set', $id, 'blocked=']);
    expect($this->sandbox->read($id)['blocked'])->toBeNull()
        ->and($this->sandbox->ok(['set', $id, 'priority=urgent']))->toBe("{$id} unchanged\n");
});

it('rejects invalid values with exit 2 and changes nothing', function () {
    $id = $this->sandbox->card('Strict');
    $commits = count($this->sandbox->boardLog());

    $bad = $this->sandbox->kanban(['set', $id, 'priority=someday']);
    expect($bad->getExitCode())->toBe(2)
        ->and($bad->getErrorOutput())->toContain("project/work/{$id}.json: priority: must be one of")
        ->and($this->sandbox->kanban(['set', $id, 'colour=red'])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'labels=+Not Valid'])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'title='.str_repeat('x', 121)])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'depends_on=+'.$id])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'tick=9'])->getExitCode())->toBe(4)
        ->and($this->sandbox->kanban(['new', 'project/nope', 'X'])->getExitCode())->toBe(4)
        ->and($this->sandbox->kanban(['new', 'project/work', 'X', '--stage=doing'])->getExitCode())->toBe(2)
        ->and(count($this->sandbox->boardLog()))->toBe($commits)
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');
});

it('moves cards through the transition table', function () {
    $id = $this->sandbox->card('Movable');

    $refused = $this->sandbox->kanban(['move', $id, 'ready']);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$id}: R3 empty body; R4 no acceptance criteria");

    $this->sandbox->ok(['set', $id, 'body=Do it', 'accept+=Done']);
    expect($this->sandbox->ok(['move', $id, 'ready']))->toBe("{$id} backlog→ready\n");

    $start = $this->sandbox->kanban(['move', $id, 'doing']);
    expect($start->getExitCode())->toBe(3)->and($start->getErrorOutput())->toContain('use `kanban start ID`');
    expect($this->sandbox->kanban(['move', $id, 'done'])->getErrorOutput())->toContain('ready → done is not a transition');
    expect($this->sandbox->kanban(['move', $id, 'decided'])->getErrorOutput())->toContain("'decided' is not a stage of a work board");

    $drop = $this->sandbox->kanban(['move', $id, 'dropped']);
    expect($drop->getExitCode())->toBe(3)->and($drop->getErrorOutput())->toContain('dropping needs a reason');
    $this->sandbox->ok(['move', $id, 'dropped', '--reason=Not needed']);
    $this->sandbox->ok(['move', $id, 'backlog']);

    $log = $this->sandbox->read($id)['log'];
    expect(array_map(fn ($e) => [$e['event'], $e['to'] ?? null, $e['via'] ?? null, $e['reason'] ?? null], array_slice($log, -3)))->toBe([
        ['stage', 'ready', 'promote', null],
        ['stage', 'dropped', null, 'Not needed'],
        ['stage', 'backlog', null, null],
    ])->and(array_slice($this->sandbox->boardLog(), 0, 3))->toBe([
        "{$id} stage dropped→backlog [owner]",
        "{$id} stage ready→dropped [owner]",
        "{$id} stage backlog→ready [owner]",
    ]);
});

it('lets only the main session force a move, and logs it', function () {
    $id = $this->sandbox->readyCard('Forced');

    expect($this->sandbox->kanban(['move', $id, 'doing', '--force'])->getExitCode())->toBe(3);

    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 'session-1']);
    $this->sandbox->ok(['move', $id, 'ready', '--force'], ['KANBAN_SESSION' => 'session-1']);

    $card = $this->sandbox->read($id);
    expect($card)->toMatchArray(['stage' => 'ready', 'claim' => null])
        ->and(end($card['log']))->toMatchArray(['by' => 'main', 'event' => 'stage', 'from' => 'doing', 'to' => 'ready', 'forced' => true])
        ->and($this->sandbox->boardLog()[0])->toBe("{$id} stage doing→ready [main]");
});

it('decides and supersedes decisions', function () {
    $old = $this->sandbox->card('Single workspace', ['--stage=decided', '--decided-on=2026-09-01', '--why=Simple'], 'project/decisions');
    $new = $this->sandbox->card('Workspace per team', ['--why=Teams choose'], 'project/decisions');

    $this->sandbox->ok(['set', $new, 'supersedes=+'.$old]);
    expect($this->sandbox->read($old)['stage'])->toBe('decided');

    $this->sandbox->ok(['move', $new, 'decided']);
    expect($this->sandbox->read($new)['decided_on'])->toMatch('/^\d{4}-\d\d-\d\d$/')
        ->and($this->sandbox->read($old))->toMatchArray(['stage' => 'superseded', 'superseded_by' => $new])
        ->and($this->sandbox->kanban(['move', $old, 'decided'])->getExitCode())->toBe(3)
        ->and($this->sandbox->ok('validate'))->toContain('ok: 2 cards');

    $dropped = $this->sandbox->card('Maybe', board: 'project/decisions');
    $this->sandbox->ok(['move', $dropped, 'dropped', '--reason=Out of scope']);
    expect($this->sandbox->read($dropped)['resolution'])->toBe('Out of scope');
});

it('creates and updates boards', function () {
    expect($this->sandbox->ok(['board', 'platform/tooling', 'Tooling', '--wip-doing=2']))->toBe("created board platform/tooling\n")
        ->and(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/tooling/board.json'), true))
        ->toMatchArray(['title' => 'Tooling', 'kind' => 'work', 'order' => 10, 'wip' => ['doing' => 2]])
        ->and(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/epic.json'), true))
        ->toMatchArray(['title' => 'Platform', 'order' => 20]);

    $this->sandbox->ok(['board', 'platform/tooling', '--order=5']);
    expect(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/tooling/board.json'), true)['order'])->toBe(5)
        ->and($this->sandbox->kanban(['board', 'platform/tooling', '--kind=decisions'])->getExitCode())->toBe(2);

    $id = $this->sandbox->card('Moves boards');
    expect($this->sandbox->ok(['move', $id, '--board=platform/tooling']))->toBe("{$id} moved to platform/tooling\n")
        ->and(is_file($this->sandbox->root."/docs/kanban/platform/tooling/{$id}.json"))->toBeTrue()
        ->and(is_file($this->sandbox->root."/docs/kanban/project/work/{$id}.json"))->toBeFalse()
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and($this->sandbox->kanban(['move', $id, '--board=project/decisions'])->getExitCode())->toBe(2);
});

it('validates the board and fixes what it can', function () {
    $id = $this->sandbox->card('Valid');
    $path = $this->sandbox->root."/docs/kanban/project/work/{$id}.json";
    file_put_contents($path, json_encode(json_decode(file_get_contents($path), true)));
    expect($this->sandbox->ok('validate'))->toContain('ok: 1 cards');

    $broken = json_decode(file_get_contents($path), true);
    $broken['colour'] = 'red';
    $broken['stage'] = 'doing';
    file_put_contents($path, json_encode($broken));
    $copy = $this->sandbox->root.'/docs/kanban/project/decisions/'.$id.'.json';
    file_put_contents($copy, '{not json');

    $invalid = $this->sandbox->kanban('validate');
    expect($invalid->getExitCode())->toBe(2)
        ->and($invalid->getOutput())->toContain("project/work/{$id}.json: colour: unknown key")
        ->toContain("project/work/{$id}.json: claim is required in doing")
        ->toContain("project/decisions/{$id}.json: invalid JSON");

    unlink($copy);
    $broken['stage'] = 'backlog';
    unset($broken['colour']);
    file_put_contents($path, json_encode($broken));
    $fixed = $this->sandbox->ok(['validate', '--fix']);
    expect($fixed)->toContain("fixed canonical project/work/{$id}.json")
        ->toContain('ok: 1 cards')
        ->and(file_get_contents($path))->toContain("\n    \"id\": ")
        ->and($this->sandbox->boardLog()[0])->toBe("{$id} created [owner]")
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');
});

it('re-ids duplicate ids with validate --fix', function () {
    $id = $this->sandbox->card('Original');
    $copy = json_decode(file_get_contents($this->sandbox->root."/docs/kanban/project/work/{$id}.json"), true);
    $this->sandbox->ok(['board', 'project/later', 'Later']);
    $copy['created'] = '2099-01-01T00:00:00.000+00:00';
    file_put_contents($this->sandbox->root."/docs/kanban/project/later/{$id}.json", json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    expect($this->sandbox->kanban('validate')->getOutput())->toContain("duplicate id {$id}");

    $fixed = $this->sandbox->ok(['validate', '--fix']);
    expect($fixed)->toMatch("/fixed renamed {$id} → ACME-\\w{6} \\(project\\/later\\/{$id}\\.json\\)/")
        ->toContain('ok: 2 cards')
        ->and(is_file($this->sandbox->root."/docs/kanban/project/work/{$id}.json"))->toBeTrue()
        ->and(glob($this->sandbox->root.'/docs/kanban/project/later/ACME-*.json'))->toHaveCount(1);
});

it('prints the board summary', function () {
    $ready = $this->sandbox->readyCard('Ready one', ['--priority=high']);
    $blocked = $this->sandbox->card('Stuck');
    $this->sandbox->ok(['set', $blocked, 'blocked=owner decision']);
    $this->sandbox->card('Chosen', ['--stage=decided', '--decided-on=2026-09-28'], 'project/decisions');

    $status = $this->sandbox->ok('status');
    expect($status)->toMatch('/^Kanban ACME: branch kanban @[0-9a-f]{7}, not published, sync off, /')
        ->toContain('WIP doing 0/6, review 0/6 · ready 1 · backlog 1 · blocked 1 · proposed decisions 0')
        ->toContain("blocked {$blocked} Stuck: \"owner decision\"")
        ->toContain("next: {$ready} high")
        ->toContain('decided (latest 1): 2026-09-28 ACME-')
        ->toContain('checks: merge driver ok · journal 0');

    expect(json_decode($this->sandbox->ok(['status', '--json']), true))->toMatchArray(['key' => 'ACME', 'next' => [$ready], 'blocked' => [$blocked]]);
});

it('writes as main when KANBAN_SESSION is set', function () {
    $id = $this->sandbox->card('By main', env: ['KANBAN_SESSION' => 'abc']);

    expect($this->sandbox->boardLog()[0])->toBe("{$id} created [main]")
        ->and($this->sandbox->read($id)['log'][0]['by'])->toBe('main');
});
