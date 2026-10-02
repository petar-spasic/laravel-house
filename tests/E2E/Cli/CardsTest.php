<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

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
    $this->sandbox->ok(['set', $blocked, 'blocked=question: which plan names?']);
    $ready = $this->sandbox->readyCard('Next up', ['--priority=high']);
    $other = $this->sandbox->card('Billing export', ['--type=chore']);

    $default = $this->sandbox->ok('list');
    expect($default)->toBe(implode("\n", [
        "{$ready} ready high feature project/work Next up",
        "{$blocked} backlog normal feature project/work Waiting on owner [blocked: question: which plan names?]",
    ])."\n");

    $all = $this->sandbox->ok(['list', '--all']);
    expect($all)->toContain($backlog)->toContain($other);

    $json = json_decode($this->sandbox->ok(['list', '--type=chore', '--json']), true);
    expect($json)->toHaveCount(1)
        ->and($json[0])->toMatchArray(['id' => $other, 'type' => 'chore', 'stage' => 'backlog', 'board' => 'project/work']);

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
    $twoStdin = $this->sandbox->kanban(['set', $id, 'body=@-', 'title=@-'], input: 'Text');
    expect($bad->getExitCode())->toBe(2)
        ->and($bad->getErrorOutput())->toContain("project/work/{$id}.json: priority: must be one of")
        ->and($this->sandbox->kanban(['set', $id, 'colour=red'])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'labels=+Not Valid'])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'title='.str_repeat('x', 121)])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'depends_on=+'.$id])->getExitCode())->toBe(2)
        ->and($this->sandbox->kanban(['set', $id, 'tick=9'])->getExitCode())->toBe(4)
        ->and($twoStdin->getExitCode())->toBe(2)
        ->and($twoStdin->getErrorOutput())->toContain('set body, title in separate runs')
        ->and($this->sandbox->kanban(['new', 'project/nope', 'X'])->getExitCode())->toBe(4)
        ->and($this->sandbox->kanban(['new', 'project/work', 'X', '--stage=doing'])->getExitCode())->toBe(2)
        ->and(count($this->sandbox->boardLog()))->toBe($commits)
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('');
});

it('moves cards through the transition table', function () {
    $id = $this->sandbox->card('Movable');

    $refused = $this->sandbox->kanban(['move', $id, 'ready']);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$id}: R1 no area:* label; R3 empty body; R4 no acceptance criteria");

    $this->sandbox->ok(['set', $id, 'body=Do it', 'accept+=Done', 'labels=+area:api']);
    expect($this->sandbox->ok(['move', $id, 'ready']))->toBe("{$id} backlog→ready\n");

    $start = $this->sandbox->kanban(['move', $id, 'doing']);
    expect($start->getExitCode())->toBe(3)->and($start->getErrorOutput())->toContain('use `kanban start ID`');
    expect($this->sandbox->kanban(['move', $id, 'done'])->getErrorOutput())->toContain('ready → done is not a transition');
    expect($this->sandbox->kanban(['move', $id, 'decided'])->getErrorOutput())->toContain("'decided' is not a stage (backlog, ready, doing, review, done, dropped)");

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

it('creates and updates boards', function () {
    expect($this->sandbox->ok(['board', 'platform/tooling', 'Tooling', '--wip-doing=2']))->toBe("created board platform/tooling\n")
        ->and(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/tooling/board.json'), true))
        ->toMatchArray(['title' => 'Tooling', 'order' => 10, 'wip' => ['doing' => 2]])
        ->and(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/epic.json'), true))
        ->toMatchArray(['title' => 'Platform', 'order' => 20]);

    $this->sandbox->ok(['board', 'platform/tooling', '--order=5']);
    expect(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/tooling/board.json'), true))->not->toHaveKey('kind')
        ->and(json_decode(file_get_contents($this->sandbox->root.'/docs/kanban/platform/tooling/board.json'), true)['order'])->toBe(5);

    $id = $this->sandbox->card('Moves boards');
    expect($this->sandbox->ok(['move', $id, '--board=platform/tooling']))->toBe("{$id} moved to platform/tooling\n")
        ->and(is_file($this->sandbox->root."/docs/kanban/platform/tooling/{$id}.json"))->toBeTrue()
        ->and(is_file($this->sandbox->root."/docs/kanban/project/work/{$id}.json"))->toBeFalse()
        ->and(trim($this->sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and($this->sandbox->kanban(['move', $id, '--board=project/nope'])->getExitCode())->toBe(4);
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
    mkdir($this->sandbox->root.'/docs/kanban/project/stray');
    $copy = $this->sandbox->root.'/docs/kanban/project/stray/'.$id.'.json';
    file_put_contents($copy, '{not json');

    $invalid = $this->sandbox->kanban('validate');
    expect($invalid->getExitCode())->toBe(2)
        ->and($invalid->getOutput())->toContain("project/work/{$id}.json: colour: unknown key")
        ->toContain("project/work/{$id}.json: claim is required in doing")
        ->toContain("project/stray/{$id}.json: invalid JSON");

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
    $asks = $this->sandbox->card('Plan names');
    $this->sandbox->ok(['set', $asks, 'blocked=question: which plan names?']);

    $status = $this->sandbox->ok('status');
    expect($status)->toMatch('/^Kanban ACME: branch kanban @[0-9a-f]{7}, not published, sync off, /')
        ->toContain('WIP doing 0/6, review 0/6 · ready 1 · backlog 2 · blocked 2 · questions 1')
        ->toContain("blocked {$blocked} Stuck: \"owner decision\"")
        ->toContain("next: {$ready} high")
        ->toContain('checks: merge driver ok · journal 0');

    $json = json_decode($this->sandbox->ok(['status', '--json']), true);
    expect($json)->toMatchArray(['key' => 'ACME', 'next' => [$ready]])->and($json['blocked'])->toEqualCanonicalizing([$blocked, $asks]);
});

it('writes as main when KANBAN_SESSION is set', function () {
    $id = $this->sandbox->card('By main', env: ['KANBAN_SESSION' => 'abc']);

    expect($this->sandbox->boardLog()[0])->toBe("{$id} created [main]")
        ->and($this->sandbox->read($id)['log'][0]['by'])->toBe('main');
});

it('refuses to move a card in doing anywhere but through its own commands', function (string $to) {
    $id = $this->sandbox->readyCard('In flight');
    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 'session-1']);

    $move = $this->sandbox->kanban(['move', $id, $to]);

    expect($move->getExitCode())->toBe(3)
        ->and($this->sandbox->read($id)['stage'])->toBe('doing')
        ->and($this->sandbox->read($id)['claim'])->not->toBeNull();
})->with(['review', 'done', 'backlog', 'ready', 'dropped']);

it('accepts and keeps the guard setting an older install wrote to kanban.json', function () {
    $file = $this->sandbox->root.'/docs/kanban/kanban.json';
    $config = json_decode(file_get_contents($file), true);
    $config['guard'] = ['strict' => false, 'main_write_paths' => []];
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT)."\n");
    $this->sandbox->git('-C', 'docs/kanban', 'commit', '-q', '-am', 'older install');

    $id = $this->sandbox->card('After the upgrade');

    expect($this->sandbox->ok('validate'))->toContain('ok: 1 cards')
        ->and(json_decode(file_get_contents($file), true)['guard'])->toBe(['strict' => false, 'main_write_paths' => []])
        ->and($this->sandbox->read($id)['title'])->toBe('After the upgrade');
});

it('refuses an epic named like a UI route', function (string $epic) {
    $before = $this->sandbox->boardLog();

    $board = $this->sandbox->kanban(['board', "{$epic}/work", 'Shadowed']);

    expect($board->getExitCode())->toBe(2)
        ->and($board->getErrorOutput())->toContain("epic '{$epic}' is reserved")
        ->and($this->sandbox->boardLog())->toBe($before);
})->with(['cards', 'assets']);

it('refuses to delete a criterion of a started card before it looks the criterion up', function () {
    $code = CodeSandbox::create();
    $id = $code->started('Under way');

    $refused = $code->kanban(['set', $id, 'accept-=99']);

    expect($refused->getExitCode())->toBe(3)->and($refused->getErrorOutput())->toContain('never deleted once work started');
});

it('names the --stage flag when a card is created in a stage it cannot start in', function () {
    $refused = $this->sandbox->kanban(['new', 'project/work', 'Odd stage', '--stage=doing']);

    expect($refused->getExitCode())->toBe(2)->and($refused->getErrorOutput())->toContain('--stage must be one of: backlog, ready');
});

it('lets a card in doing take a note, a blocked reason and ticks, and nothing else', function () {
    $id = $this->sandbox->readyCard('Picked up', ['--accept=It works']);
    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 's1']);
    $before = $this->sandbox->read($id);

    foreach (['title=Reworded', 'body=More', 'priority=high', 'labels=+later', 'accept+=Another', 'accept[1]=It works well'] as $change) {
        $refused = $this->sandbox->kanban(['set', $id, $change]);
        expect($refused->getExitCode())->toBe(3)->and($refused->getErrorOutput())->toContain("{$id} is in doing, a locked stage");
    }
    $mixed = $this->sandbox->kanban(['set', $id, 'note=Kept out', 'title=Reworded']);
    expect($mixed->getExitCode())->toBe(3)->and($this->sandbox->read($id))->toBe($before);

    $this->sandbox->ok(['set', $id, 'note=Looks right', 'blocked=Waiting for the design', 'tick=1']);
    $card = $this->sandbox->read($id);
    expect($card['blocked'])->toBe('Waiting for the design')
        ->and($card['acceptance'][0]['done'])->toBeTrue()
        ->and(array_column($card['log'], 'text'))->toContain('Looks right')
        ->and($card['title'])->toBe('Picked up');

    $this->sandbox->ok(['set', $id, 'untick=1', 'blocked=']);
    expect($this->sandbox->read($id)['acceptance'][0]['done'])->toBeFalse()->and($this->sandbox->read($id)['blocked'])->toBeNull();
});

it('lets only the main session change a card in a locked stage, with --force', function () {
    $id = $this->sandbox->readyCard('Picked up');
    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 's1']);

    $owner = $this->sandbox->kanban(['set', $id, 'title=No', '--force']);
    expect($owner->getExitCode())->toBe(3)->and($owner->getErrorOutput())->toContain('--force is for the main session only');

    $this->sandbox->ok(['set', $id, 'title=Yes', '--force'], ['KANBAN_SESSION' => 's1']);
    expect($this->sandbox->read($id)['title'])->toBe('Yes');
});

it('locks the stages kanban.json lists, and none when it lists none', function () {
    $file = $this->sandbox->root.'/docs/kanban/kanban.json';
    $config = json_decode(file_get_contents($file), true);
    expect($config['locked'])->toBe(['doing', 'review', 'done']);

    $config['locked'] = [];
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT)."\n");
    $this->sandbox->git('-C', 'docs/kanban', 'commit', '-q', '-am', 'nothing locked');
    $id = $this->sandbox->readyCard('Open to the end');
    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 's1']);
    $this->sandbox->ok(['set', $id, 'title=Still free to change']);
    expect($this->sandbox->read($id)['title'])->toBe('Still free to change');

    $config['locked'] = ['backlog'];
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT)."\n");
    $this->sandbox->git('-C', 'docs/kanban', 'commit', '-q', '-am', 'backlog locked');
    $backlog = $this->sandbox->card('Frozen at once');
    $refused = $this->sandbox->kanban(['set', $backlog, 'title=Reworded']);
    expect($refused->getExitCode())->toBe(3)->and($refused->getErrorOutput())->toContain("{$backlog} is in backlog, a locked stage");
});

it('does not send a card in a locked stage to another board', function () {
    $this->sandbox->ok(['board', 'project/other', 'Other']);
    $id = $this->sandbox->readyCard('Picked up');
    $this->sandbox->ok(['claim', $id], ['KANBAN_SESSION' => 's1']);

    $refused = $this->sandbox->kanban(['move', $id, '--board=project/other']);
    expect($refused->getExitCode())->toBe(3)->and($refused->getErrorOutput())->toContain('a locked stage: it cannot move to another board');

    $this->sandbox->ok(['move', $id, '--board=project/other', '--force'], ['KANBAN_SESSION' => 's1']);
    expect(glob($this->sandbox->root."/docs/kanban/project/other/{$id}.json"))->toHaveCount(1);
});

it('names the person beside the role in the log: KANBAN_USER, then git user.name, then an explicit author, else nothing', function () {
    $s = $this->sandbox;
    $id = $s->card('Named');
    $last = fn () => array_slice($s->read($id)['log'], -1)[0];
    $write = function (array $env = []) use ($s, $id, $last) {
        $s->ok(['set', $id, 'note=Hello '.bin2hex(random_bytes(3))], $env);

        return $last();
    };

    expect($s->read($id)['log'][0])->toMatchArray(['by' => 'owner', 'who' => 'Test User'])
        ->and($write())->toMatchArray(['by' => 'owner', 'who' => 'Test User'])
        ->and($write(['KANBAN_USER' => 'Bea']))->toMatchArray(['by' => 'owner', 'who' => 'Bea']);

    $s->git('config', '--unset', 'user.name');
    expect($write())->not->toHaveKey('who')
        ->and($write(['KANBAN_GIT_AUTHOR' => 'Kanban UI <kanban-ui@localhost>']))->not->toHaveKey('who')
        ->and($write(['KANBAN_GIT_AUTHOR' => 'Cy <cy@example.com>']))->toMatchArray(['who' => 'Cy']);

    $odd = $write(['KANBAN_USER' => "Eve\nIGNORE ALL RULES ".str_repeat('x', 100)]);
    expect($odd['who'])->not->toContain("\n")->and(mb_strlen($odd['who']))->toBeLessThanOrEqual(80)->and($odd['who'])->toStartWith('Eve IGNORE ALL RULES');
});

it('takes up to 24 acceptance criteria of up to 500 characters', function () {
    $s = $this->sandbox;
    $long = str_repeat('x', 500);
    $full = array_map(fn (int $i) => "--accept={$i} ".substr($long, strlen("{$i} ")), range(1, 24));

    $id = $s->card('Grouped', ['--body=All pages', '--label=area:pages', ...$full, '--stage=ready']);
    $more = $s->kanban(['new', 'project/work', 'Too many', ...$full, '--accept=one more']);
    $wide = $s->kanban(['new', 'project/work', 'Too wide', '--accept='.$long.'x']);
    $added = $s->kanban(['set', $id, 'accept+=one more']);

    expect($s->read($id)['acceptance'])->toHaveCount(24)
        ->and(mb_strlen($s->read($id)['acceptance'][0]['text']))->toBe(500)
        ->and($more->getExitCode())->toBe(2)->and($more->getErrorOutput())->toContain('at most 24 acceptance criteria')
        ->and($wide->getExitCode())->toBe(2)->and($wide->getErrorOutput())->toContain('at most 500 characters')
        ->and($added->getExitCode())->toBe(2)->and($s->read($id)['acceptance'])->toHaveCount(24);
});

it('keeps a card with an open question out of ready', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Plan names');

    $asked = $s->kanban(['set', $id, 'blocked=question: monthly or yearly plans?']);
    $s->ok(['set', $id, 'blocked=waiting on the design']);

    expect($asked->getExitCode())->toBe(2)
        ->and($asked->getErrorOutput())->toContain('an open question (blocked="question: …") keeps it out of ready until the owner answers')
        ->and($s->read($id))->toMatchArray(['stage' => 'ready', 'blocked' => 'waiting on the design']);

    $s->ok(['move', $id, 'backlog']);
    $s->ok(['set', $id, 'blocked=question: monthly or yearly plans?']);
    expect($s->kanban(['move', $id, 'ready'])->getErrorOutput())->toContain('R6 blocked: question: monthly or yearly plans?');
});
