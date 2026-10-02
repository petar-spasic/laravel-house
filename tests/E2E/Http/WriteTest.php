<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    UiSandbox::boot($this->sandbox->root);
});

function cardFile(Sandbox $s, string $id): string
{
    return (string) file_get_contents(glob($s->root."/docs/kanban/*/*/{$id}.json")[0]);
}

function rev(Sandbox $s, string $id): string
{
    return sha1(cardFile($s, $id));
}

/** A UI write: the way the page script sends it. */
function send(object $test, string $method, string $url, array $data = [])
{
    return $test->json($method, '/kanban/_api'.$url, $data, ['X-Kanban' => '1']);
}

it('moves a card, commits it on the kanban branch as owner and answers with the card', function () {
    $s = $this->sandbox;
    $id = $s->card('Promote me', ['--body=Build it', '--accept=It works', '--label=area:api']);

    $response = send($this, 'POST', "/cards/{$id}/stage", ['to' => 'ready', 'rev' => rev($s, $id)])->assertOk();

    $card = $s->read($id);
    expect($response->json('card.stage'))->toBe('ready')->and($response->json('card.rev'))->toBe(rev($s, $id))
        ->and($card['stage'])->toBe('ready')
        ->and(end($card['log']))->toMatchArray(['by' => 'owner', 'event' => 'stage', 'from' => 'backlog', 'to' => 'ready', 'via' => 'promote'])
        ->and($s->boardLog()[0])->toBe("{$id} stage backlog→ready [owner]")
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('');
});

it('moves a card back and drops it with a reason', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Back to backlog');

    send($this, 'POST', "/cards/{$id}/stage", ['to' => 'backlog', 'rev' => rev($s, $id)])->assertOk();
    send($this, 'POST', "/cards/{$id}/stage", ['to' => 'dropped', 'reason' => 'not needed', 'rev' => rev($s, $id)])->assertOk();

    $card = $s->read($id);
    expect($card['stage'])->toBe('dropped')->and(end($card['log']))->toMatchArray(['to' => 'dropped', 'reason' => 'not needed']);
});

it('refuses a move the ready policy rejects with 422 and leaves the file alone', function () {
    $s = $this->sandbox;
    $id = $s->card('Bare');
    $before = cardFile($s, $id);
    $commits = count($s->boardLog());

    $refused = send($this, 'POST', "/cards/{$id}/stage", ['to' => 'ready', 'rev' => sha1($before)])->assertStatus(422);
    expect($refused->json('message'))->toContain('R3 empty body')->toContain('R4 no acceptance criteria')
        ->and(implode(' ', $refused->json('details')))->toContain('R3');
    send($this, 'POST', "/cards/{$id}/stage", ['to' => 'dropped', 'rev' => sha1($before)])
        ->assertStatus(422)->assertJsonPath('message', 'dropping needs a reason (--reason)');

    expect(cardFile($s, $id))->toBe($before)->and(count($s->boardLog()))->toBe($commits);
});

it('refuses a question block on a ready card with 422 and leaves the file alone', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Print notes');
    $before = cardFile($s, $id);

    $refused = send($this, 'PATCH', "/cards/{$id}", ['rev' => sha1($before), 'blocked' => 'question: which renderer?'])->assertStatus(422);
    expect($refused->json('message'))->toContain('question');
    expect(cardFile($s, $id))->toBe($before);
});

it('refuses doing, review and done as CLI only', function (string $to) {
    $s = $this->sandbox;
    $id = $s->readyCard('Not from the UI');
    $before = cardFile($s, $id);

    send($this, 'POST', "/cards/{$id}/stage", ['to' => $to, 'rev' => sha1($before)])
        ->assertStatus(422)->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'CLI only'))->etc());

    expect(cardFile($s, $id))->toBe($before);
})->with(['doing', 'review', 'done']);

it('answers a stale rev with 409 and the fresh card, for every kind of write', function (string $method, string $path, array $data) {
    $s = $this->sandbox;
    $id = $s->card('Contested', ['--body=Build it', '--accept=It renders', '--label=area:ui']);
    $stale = rev($s, $id);
    $s->ok(['set', $id, 'title=Changed elsewhere']);
    $before = cardFile($s, $id);

    $response = send($this, $method, str_replace('ID', $id, $path), $data + ['rev' => $stale])->assertStatus(409);

    expect($response->json('message'))->toContain('changed since it was read')
        ->and($response->json('card.title'))->toBe('Changed elsewhere')
        ->and($response->json('card.rev'))->toBe(sha1($before))
        ->and(cardFile($s, $id))->toBe($before);
})->with([
    'stage' => ['POST', '/cards/ID/stage', ['to' => 'dropped', 'reason' => 'x']],
    'promote' => ['POST', '/cards/ID/stage', ['to' => 'ready']],
    'patch' => ['PATCH', '/cards/ID', ['priority' => 'urgent']],
    'note' => ['POST', '/cards/ID/notes', ['text' => 'hello']],
]);

it('changes priority, title, type, labels, body and blocked in one commit as owner', function () {
    $s = $this->sandbox;
    $id = $s->card('Old title');

    $response = send($this, 'PATCH', "/cards/{$id}", [
        'rev' => rev($s, $id), 'title' => 'New title', 'priority' => 'urgent', 'type' => 'bug', 'labels' => ['area:ui', 'ux'],
        'body' => "## Plan\n\nDo it", 'blocked' => 'waiting on owner',
    ])->assertOk();

    $card = $s->read($id);
    expect($card)->toMatchArray(['title' => 'New title', 'priority' => 'urgent', 'type' => 'bug', 'labels' => ['area:ui', 'ux'], 'body' => "## Plan\n\nDo it", 'blocked' => 'waiting on owner'])
        ->and($response->json('card.body_html'))->toContain('<h2>Plan</h2>')
        ->and($s->boardLog()[0])->toBe("{$id} set blocked,body,labels,priority,title,type [owner]")
        ->and(end($card['log']))->toMatchArray(['by' => 'owner', 'event' => 'set']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'blocked' => ''])->assertOk()->assertJsonPath('card.blocked', null);
    expect($s->read($id)['blocked'])->toBeNull();
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'blocked' => null, 'labels' => []])->assertOk();
    expect($s->read($id)['labels'])->toBe([]);
});

it('writes only the field a patch names, on top of whatever else changed since the page read the card', function () {
    $s = $this->sandbox;
    $id = $s->card('Old title', ['--body=Original', '--label=area:ui']);
    $stale = rev($s, $id);
    $s->ok(['set', $id, 'priority=high', 'title=Renamed elsewhere', 'labels=+ux']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => $stale, 'body' => 'Typed on a stale page'])->assertStatus(409)
        ->assertJsonPath('card.title', 'Renamed elsewhere');
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'body' => 'Typed on a stale page'])->assertOk();

    expect($s->read($id))->toMatchArray(['body' => 'Typed on a stale page', 'title' => 'Renamed elsewhere', 'priority' => 'high', 'labels' => ['area:ui', 'ux']]);
});

it('stamps the configured name beside the role on what the page writes', function () {
    $s = $this->sandbox;
    UiSandbox::boot($s->root, 'local', ['kanban.user' => 'Ana']);
    $id = $s->card('By Ana');

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'title' => 'Renamed by Ana'])->assertOk();
    send($this, 'POST', "/cards/{$id}/notes", ['rev' => rev($s, $id), 'text' => 'A note'])->assertOk();

    $log = $s->read($id)['log'];
    expect(array_slice($log, -2))->each->toMatchArray(['by' => 'owner', 'who' => 'Ana'])
        ->and($log[0])->toMatchArray(['by' => 'owner', 'who' => 'Test User']);
});

it('leaves the file and history alone when a patch changes nothing', function () {
    $s = $this->sandbox;
    $id = $s->card('Same');
    $commits = count($s->boardLog());

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'title' => 'Same', 'priority' => 'normal'])->assertOk();

    expect(count($s->boardLog()))->toBe($commits);
});

it('sets dependencies by id or prefix and refuses an unknown card', function () {
    $s = $this->sandbox;
    $a = $s->card('Dep A');
    $b = $s->card('Dep B');
    $id = $s->card('Needs both');

    $both = [$a, $b];
    sort($both);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'depends_on' => [$a, substr($b, 5)]])->assertOk()
        ->assertJsonPath('card.depends_on.*.id', $both)->assertJsonPath('card.deps', ['open' => 2, 'total' => 2]);
    expect($s->read($id)['depends_on'])->toBe($both);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'depends_on' => ['ACME-NOPE99']])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'depends_on' => [$id]])->assertStatus(422);
    expect($s->read($id)['depends_on'])->toBe($both);
});

it('rejects invalid input with 422 and a list of what is wrong', function (array $data, string $field) {
    $s = $this->sandbox;
    $id = $s->card('Keep me');
    $before = cardFile($s, $id);

    $response = send($this, 'PATCH', "/cards/{$id}", $data + ['rev' => sha1($before)])->assertStatus(422);

    expect($response->json('message'))->toBe('Not saved')->and(implode(' ', $response->json('details')))->toContain($field)
        ->and(cardFile($s, $id))->toBe($before);
})->with([
    'priority' => [['priority' => 'asap'], 'priority'],
    'empty title' => [['title' => ''], 'title'],
    'type' => [['type' => 'epic'], 'type'],
    'labels shape' => [['labels' => 'area:ui'], 'labels'],
    'blocked too long' => [['blocked' => str_repeat('x', 501)], 'blocked'],
    'title too long' => [['title' => str_repeat('x', 121)], 'title'],
    'label pattern' => [['labels' => ['Bug Fix']], 'labels.0'],
    'too many labels' => [['labels' => array_map(fn ($i) => "l{$i}", range(1, 11))], 'labels'],
    'criterion too long' => [['acceptance' => [['text' => str_repeat('x', 501)]]], 'acceptance.0.text'],
    'too many criteria' => [['acceptance' => array_map(fn ($i) => ['text' => "c{$i}"], range(1, 25))], 'acceptance'],
]);

it('needs a rev, and 404s an unknown card', function () {
    $s = $this->sandbox;
    $id = $s->card('Some card');

    send($this, 'PATCH', "/cards/{$id}", ['priority' => 'high'])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['priority' => 'high', 'rev' => 'nope'])->assertStatus(422);
    send($this, 'PATCH', '/cards/ACME-ZZZZZZ', ['priority' => 'high', 'rev' => sha1('x')])->assertNotFound();
    send($this, 'POST', '/cards/ACME-ZZZZZZ/notes', ['text' => 'x', 'rev' => sha1('x')])->assertNotFound();
});

it('adds, ticks, edits and removes acceptance criteria, never reusing a removed id', function () {
    $s = $this->sandbox;
    $id = $s->card('Criteria', ['--accept=First', '--accept=Second']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [
        ['id' => 1, 'text' => 'First', 'done' => true], ['id' => 2, 'text' => 'Second, reworded', 'done' => false], ['text' => 'Third'],
    ]])->assertOk()->assertJsonPath('card.progress', ['done' => 1, 'total' => 3]);
    expect($s->read($id)['acceptance'])->toBe([
        ['id' => 1, 'text' => 'First', 'done' => true], ['id' => 2, 'text' => 'Second, reworded', 'done' => false], ['id' => 3, 'text' => 'Third', 'done' => false],
    ]);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => 'First', 'done' => true], ['id' => 2, 'text' => 'Second, reworded', 'done' => false]]])->assertOk();
    $card = $s->read($id);
    expect(array_column($card['acceptance'], 'id'))->toBe([1, 2])
        ->and(end($card['log']))->toMatchArray(['event' => 'set', 'acceptance_removed' => [3]]);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => 'First', 'done' => true], ['id' => 2, 'text' => 'Second, reworded', 'done' => false], ['text' => 'Fourth']]])->assertOk();
    expect(array_column($s->read($id)['acceptance'], 'id'))->toBe([1, 2, 4]);
});

it('never deletes an acceptance criterion once work has started, but still ticks it', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Started');
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, ['branch' => 'card/x', 'stack' => null]);
    $before = $s->read($id);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => []])->assertStatus(422)
        ->assertJsonPath('message', 'acceptance criteria are never deleted once work started');
    expect($s->read($id)['acceptance'])->toBe($before['acceptance']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => 'It works', 'done' => true]]])->assertOk();
    expect($s->read($id)['acceptance'][0]['done'])->toBeTrue();
});

it('ticks a criterion the CLI stored with trailing whitespace, without counting it as an edit of its text', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Started', ['--accept=Padded ']);
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, ['branch' => 'card/x', 'stack' => null]);
    $text = $s->read($id)['acceptance'][1]['text'] ?? null;

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => array_map(
        fn (array $i) => ['id' => $i['id'], 'text' => $i['text'], 'done' => $i['done'] || $i['text'] === $text],
        $s->read($id)['acceptance'],
    )])->assertOk();
    expect($s->read($id)['acceptance'][1])->toMatchArray(['text' => $text, 'done' => true]);
});

/** Brings a ready card to $stage the way the workflow does. */
function settle(string $id, string $stage): void
{
    $transitions = new Transitions(app(Store::class));
    $main = new Actor('main', 's1');
    $transitions->start($id, $main, null, ['branch' => 'card/x', 'stack' => null]);
    if ($stage !== 'doing') {
        $transitions->apply($id, $main);
    }
    if ($stage === 'done') {
        $transitions->finish($id, 'abc1234', $main);
    }
}

it('lets a card in doing, review or done take a note, a blocked reason and ticks from the UI, and nothing else', function (string $stage) {
    $s = $this->sandbox;
    $id = $s->readyCard('Picked up');
    settle($id, $stage);
    $before = cardFile($s, $id);

    foreach ([['title' => 'Reworded'], ['priority' => 'urgent'], ['labels' => ['later']], ['body' => 'More'], ['acceptance' => [['id' => 1, 'text' => 'Changed', 'done' => false]]], ['acceptance' => [['id' => 1, 'text' => 'It works', 'done' => false], ['text' => 'Another']]]] as $change) {
        send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id)] + $change)->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, "{$id} is in {$stage}, a locked stage: "));
    }
    expect(cardFile($s, $id))->toBe($before);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'blocked' => 'Waiting for the design'])->assertOk()->assertJsonPath('card.blocked', 'Waiting for the design');
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => 'It works', 'done' => true]]])->assertOk();
    send($this, 'POST', "/cards/{$id}/notes", ['rev' => rev($s, $id), 'text' => 'Looks right'])->assertOk();
    $card = $s->read($id);
    expect($card['blocked'])->toBe('Waiting for the design')->and($card['acceptance'][0]['done'])->toBeTrue()->and($card['title'])->toBe('Picked up');
})->with(['doing', 'review', 'done']);

it('refuses acceptance edits that name an unknown criterion or exceed the limit', function () {
    $s = $this->sandbox;
    $id = $s->card('Criteria', ['--accept=First']);
    $before = cardFile($s, $id);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => sha1($before), 'acceptance' => [['id' => 9, 'text' => 'Ghost', 'done' => false]]])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['rev' => sha1($before), 'acceptance' => array_map(fn ($i) => ['text' => "c{$i}"], range(1, 25))])->assertStatus(422);

    expect(cardFile($s, $id))->toBe($before);
});

it('adds a note as owner and escapes it in the log', function () {
    $s = $this->sandbox;
    $id = $s->card('Noted');

    $response = send($this, 'POST', "/cards/{$id}/notes", ['rev' => rev($s, $id), 'text' => '  Owner says <b>hi</b>  '])->assertOk();

    expect($s->read($id)['log'][1])->toMatchArray(['by' => 'owner', 'event' => 'note', 'text' => 'Owner says <b>hi</b>'])
        ->and($response->json('card.log.0.text'))->toBe('Owner says <b>hi</b>')
        ->and($s->boardLog()[0])->toBe("{$id} note [owner]");
    send($this, 'POST', "/cards/{$id}/notes", ['rev' => rev($s, $id), 'text' => '   '])->assertStatus(422);
    send($this, 'POST', "/cards/{$id}/notes", ['rev' => rev($s, $id), 'text' => str_repeat('x', 5001)])->assertStatus(422);
});

it('creates a card in the backlog with just a title', function () {
    $s = $this->sandbox;

    $response = send($this, 'POST', '/project/work/cards', ['title' => 'Quick idea'])->assertCreated();

    $id = $response->json('card.id');
    expect($id)->toStartWith('ACME-')->and($response->json('card.stage'))->toBe('backlog')
        ->and($s->read($id))->toMatchArray(['title' => 'Quick idea', 'type' => 'feature', 'priority' => 'normal', 'stage' => 'backlog'])
        ->and($s->boardLog()[0])->toBe("{$id} created [owner]");
});

it('creates a card with every field, straight into ready when it passes the policy', function () {
    $s = $this->sandbox;
    $dep = $s->card('Upstream');

    $response = send($this, 'POST', '/project/work/cards', [
        'title' => 'Full card', 'type' => 'bug', 'priority' => 'high', 'labels' => ['area:api'], 'body' => 'Fix it',
        'acceptance' => ['It is fixed', 'It is tested'], 'depends_on' => [$dep], 'stage' => 'ready',
    ])->assertCreated();

    expect($s->read($response->json('card.id')))->toMatchArray([
        'title' => 'Full card', 'type' => 'bug', 'priority' => 'high', 'labels' => ['area:api'], 'body' => 'Fix it', 'stage' => 'ready', 'depends_on' => [$dep],
    ])->and($response->json('card.progress'))->toBe(['done' => 0, 'total' => 2]);
});

it('creates a card with 24 criteria of 500 characters and no more', function () {
    $s = $this->sandbox;
    $criteria = array_map(fn (int $i) => str_pad("Criterion {$i} ", 500, 'x'), range(1, 24));

    $id = send($this, 'POST', '/project/work/cards', ['title' => 'Grouped', 'acceptance' => $criteria])->assertCreated()->json('card.id');
    send($this, 'POST', '/project/work/cards', ['title' => 'Too many', 'acceptance' => [...$criteria, 'one more']])->assertStatus(422);
    send($this, 'POST', '/project/work/cards', ['title' => 'Too wide', 'acceptance' => [str_repeat('x', 501)]])->assertStatus(422);

    expect($s->read($id)['acceptance'])->toHaveCount(24)->and(glob($s->root.'/docs/kanban/project/work/ACME-*.json'))->toHaveCount(1);
});

it('refuses a new card the ready policy rejects, creating nothing', function () {
    $s = $this->sandbox;
    $commits = count($s->boardLog());

    $response = send($this, 'POST', '/project/work/cards', ['title' => 'Too early', 'stage' => 'ready'])->assertStatus(422);

    expect($response->json('message'))->toContain('R3 empty body')->and(count($s->boardLog()))->toBe($commits)
        ->and(glob($s->root.'/docs/kanban/project/work/ACME-*.json'))->toBe([]);
});

it('refuses to create on an unknown board or without a title', function () {
    send($this, 'POST', '/project/nothing/cards', ['title' => 'x'])->assertNotFound();
    send($this, 'POST', '/project/work/cards', ['title' => ''])->assertStatus(422);
    send($this, 'POST', '/project/work/cards', ['title' => 'x', 'priority' => 'asap'])->assertStatus(422);
    send($this, 'POST', '/project/work/cards', ['title' => 'x', 'depends_on' => ['ACME-NOPE99']])->assertStatus(422);
});

it('stores a blocked reason of "0" as a reason', function () {
    $s = $this->sandbox;
    $id = $s->card('Zero');

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'blocked' => '0'])->assertOk()->assertJsonPath('card.blocked', '0');

    expect($s->read($id)['blocked'])->toBe('0');
});

it('keeps Markdown and empty strings as typed even when the app trims and nulls request strings globally', function () {
    $s = $this->sandbox;
    $id = $s->card('Verbatim', ['--body=old']);
    $kernel = app(Kernel::class);
    $kernel->prependMiddleware(TrimStrings::class);
    $kernel->prependMiddleware(ConvertEmptyStringsToNull::class);
    $code = "\n    indented code\n\nafter\n";

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'body' => $code, 'blocked' => ''])->assertOk();

    expect($s->read($id)['body'])->toBe($code)->and($s->read($id)['blocked'])->toBeNull();
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'title' => '  Padded title  '])->assertOk()->assertJsonPath('card.title', 'Padded title');
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'title' => '   '])->assertStatus(422);
});

it('keeps Markdown as typed when the UI is mounted at the root', function () {
    $s = $this->sandbox;
    $id = $s->card('At the root', ['--body=old']);
    UiSandbox::boot($s->root, config: ['kanban.ui.path' => '']);
    app(Kernel::class)->prependMiddleware(TrimStrings::class);
    $code = "\n    indented code\n";

    $this->json('PATCH', "/_api/cards/{$id}", ['rev' => rev($s, $id), 'body' => $code], ['X-Kanban' => '1'])->assertOk();

    expect($s->read($id)['body'])->toBe($code);
});

it('stores criteria without the whitespace around them, as when creating', function () {
    $s = $this->sandbox;
    $id = $s->card('Padded criteria', ['--accept=First']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => '  First  ', 'done' => false], ['text' => "  Second\n"]]])->assertOk();

    expect(array_column($s->read($id)['acceptance'], 'text'))->toBe(['First', 'Second']);
});

it('words a refused initial stage for the UI, not the CLI', function () {
    send($this, 'POST', '/project/work/cards', ['title' => 'Bad stage', 'stage' => 'doing'])->assertStatus(422)
        ->assertJsonPath('message', 'stage must be one of: backlog, ready');
});

it('leaves a card alone when asked to move it to the stage it is in', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Already ready');
    $commits = count($s->boardLog());

    send($this, 'POST', "/cards/{$id}/stage", ['to' => 'ready', 'rev' => rev($s, $id)])->assertOk()->assertJsonPath('card.stage', 'ready');
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, ['branch' => 'card/x', 'stack' => null]);
    $started = count($s->boardLog());
    send($this, 'POST', "/cards/{$id}/stage", ['to' => 'doing', 'rev' => rev($s, $id)])->assertOk()->assertJsonPath('card.stage', 'doing');

    expect(count($s->boardLog()))->toBe($started)->and($started)->toBeGreaterThan($commits)
        ->and(collect($s->read($id)['log'])->where('event', 'stage')->every(fn ($e) => $e['from'] !== $e['to']))->toBeTrue();
});

it('refuses a blank acceptance criterion when creating, and stores criteria trimmed', function () {
    $s = $this->sandbox;

    send($this, 'POST', '/project/work/cards', ['title' => 'Blank', 'acceptance' => ['  ']])->assertStatus(422);
    $id = send($this, 'POST', '/project/work/cards', ['title' => 'Padded', 'acceptance' => ['  works  ']])->assertCreated()->json('card.id');

    expect($s->read($id)['acceptance'][0]['text'])->toBe('works');
});

it('reports a rebase in progress as such, not as a card that changed', function () {
    $s = $this->sandbox;
    $id = $s->card('Blocked by a rebase');
    $gitdir = trim($s->boardGit('rev-parse', '--absolute-git-dir'));
    mkdir($gitdir.'/rebase-merge');

    $response = send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'priority' => 'high'])->assertStatus(503);

    expect($response->json('message'))->toContain('rebase is in progress')->and($response->json())->not->toHaveKey('card');
    send($this, 'POST', '/project/work/cards', ['title' => 'Nope'])->assertStatus(503);
});

it('refuses the decision type', function () {
    $s = $this->sandbox;
    $id = $s->card('Typed');

    send($this, 'POST', '/project/work/cards', ['title' => 'Wrong', 'type' => 'decision'])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'type' => 'decision'])->assertStatus(422);
    expect($s->read($id)['type'])->toBe('feature');
});

it('refuses a write whose Origin is another site, even from a same-site page', function () {
    $s = $this->sandbox;
    $id = $s->card('Origin check');
    $write = fn (array $headers) => $this->json('PATCH', "/kanban/_api/cards/{$id}", ['rev' => rev($s, $id), 'priority' => 'high'], ['X-Kanban' => '1'] + $headers);

    $write(['Origin' => 'http://evil.example', 'Sec-Fetch-Site' => 'same-site'])->assertForbidden();
    $write(['Origin' => 'null'])->assertForbidden();
    expect($s->read($id)['priority'])->toBe('normal');
    $write(['Origin' => 'http://localhost', 'Sec-Fetch-Site' => 'same-origin'])->assertOk();
    $write([])->assertOk();
});

it('reads an empty string in the body as no value, whether or not the host converts empty strings', function () {
    $s = $this->sandbox;
    $id = $s->card('Blank fields', ['--body=Build it', '--accept=First', '--label=area:api']);

    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'type' => ''])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'priority' => ''])->assertStatus(422);
    send($this, 'PATCH', "/cards/{$id}", ['rev' => rev($s, $id), 'acceptance' => [['id' => 1, 'text' => 'First', 'done' => false], ['id' => '', 'text' => 'Second']]])->assertOk();
    send($this, 'POST', "/cards/{$id}/stage", ['rev' => rev($s, $id), 'to' => 'ready', 'reason' => ''])->assertOk();
    $made = send($this, 'POST', '/project/work/cards', ['title' => 'Blank stage', 'stage' => '', 'type' => '', 'priority' => ''])->assertStatus(201);

    $card = $s->read($id);
    expect(array_column($card['acceptance'], 'id'))->toBe([1, 2])
        ->and($card['type'])->toBe('feature')
        ->and(end($card['log']))->toMatchArray(['event' => 'stage', 'to' => 'ready'])->not->toHaveKey('reason')
        ->and($s->read($made->json('card.id')))->toMatchArray(['stage' => 'backlog', 'type' => 'feature', 'priority' => 'normal']);
});
