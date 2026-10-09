<?php

use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    UiSandbox::boot($this->sandbox->root);
});

/** Where a worktree stack answers: the host comes from the environment. */
function stackUrl(int $port): string
{
    return 'http://'.CodeSandbox::lanHost().':'.$port;
}

/** Binds an agent record to a card, as the binder does. */
function agent(Sandbox $s, string $name, string $card, array $extra = []): string
{
    @mkdir($s->root.'/.git/laravel-house/agents', 0775, true);
    $file = $s->root."/.git/laravel-house/agents/{$name}.json";
    file_put_contents($file, json_encode($extra + ['agent_id' => $name, 'agent_type' => 'kanban-worker', 'card' => $card, 'worktree' => null,
        'bound_at' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - 300), 'stopped_at' => null, 'stop_blocks' => 0]));

    return $file;
}

it('lists epics and boards with per-stage counts, uncached and unindexed', function () {
    $s = $this->sandbox;
    $s->card('Parked');
    $s->readyCard('Next up');

    $response = $this->getJson('/kanban/_api/boards')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertJsonPath('key', 'ACME')
        ->assertJsonPath('notices', [])
        ->assertJsonPath('boards.*.ref', ['work'])
        ->assertJsonPath('boards.0.counts', ['backlog' => 1, 'planning' => 0, 'ready' => 1, 'doing' => 0, 'review' => 0, 'done' => 0, 'dropped' => 0])
        ->assertJsonPath('epics', []);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('says so when no board is attached on this machine', function () {
    $bare = Sandbox::create('bare');
    UiSandbox::boot($bare->root);

    $this->getJson('/kanban/_api/boards')->assertOk()->assertJsonPath('epics', [])
        ->assertJsonPath('notices.0', 'No board at docs/kanban on this machine: run `vendor/bin/kanban attach` (or `php artisan kanban:install` in a new project).');
});

it('notices board problems', function () {
    $s = $this->sandbox;
    $id = $s->card('Broken');
    file_put_contents($s->root."/docs/kanban/work/{$id}.json", '{not json');

    $this->getJson('/kanban/_api/boards')->assertOk()->assertJsonPath('notices.0', '1 board files have problems: run `vendor/bin/kanban validate`.');
});

it('describes a board as columns of card summaries in pull order', function () {
    $s = $this->sandbox;
    $low = $s->card('Low one', ['--priority=low']);
    $backlog = $s->card('Write <b>docs</b>', ['--priority=high', '--label=area:docs', '--accept=Docs exist', '--accept=Docs are linked']);
    $s->ok(['set', $backlog, 'tick=1']);
    $dep = $s->card('Upstream');
    $ready = $s->readyCard('Blocked one', ["--depends={$dep}"]);
    $s->ok(['set', $ready, 'blocked=waiting on owner']);
    $doing = $s->readyCard('In flight');
    (new Transitions(app(Store::class)))->start($doing, new Actor('main', 's1'), null, [
        'branch' => 'card/x', 'stack' => ['project' => 'app-wt-x', 'slot' => 1, 'ports' => [], 'url' => stackUrl(21010)],
    ]);
    agent($s, 'a1', $doing);
    $dropped = $s->card('Abandoned');
    $s->ok(['move', $dropped, 'dropped', '--reason=no longer needed']);

    $response = $this->getJson('/kanban/_api/work')->assertOk()
        ->assertJsonPath('ref', 'work')
        ->assertJsonPath('moves', ['backlog' => ['planning', 'dropped'], 'planning' => ['backlog', 'dropped'], 'ready' => ['planning', 'backlog', 'dropped'], 'doing' => [], 'review' => [], 'done' => [], 'dropped' => ['backlog']])
        ->assertJsonPath('locked', ['doing', 'review', 'done'])
        ->assertJsonPath('stages.*.stage', ['backlog', 'planning', 'ready', 'doing', 'review', 'done', 'dropped'])
        ->assertJsonPath('stages.*.collapsed', [false, false, false, false, false, false, true])
        ->assertJsonPath('stages.0.total', 3)
        ->assertJsonPath('stages.0.cards.*.id', [$backlog, $dep, $low]);
    $cards = collect($response->json('stages'))->pluck('cards')->flatten(1)->keyBy('id');

    expect($cards[$backlog])->toMatchArray([
        'short' => substr($backlog, 5), 'title' => 'Write <b>docs</b>', 'stage' => 'backlog', 'priority' => 'high', 'type' => 'feature',
        'labels' => ['area:docs'], 'blocked' => null, 'deps' => ['open' => 0, 'total' => 0], 'progress' => ['done' => 1, 'total' => 2],
        'agent' => null, 'url' => null, 'rev' => sha1_file($s->root."/docs/kanban/work/{$backlog}.json"),
    ])->and($cards[$backlog]['since'])->toBeInt()
        ->and($cards[$ready])->toMatchArray(['blocked' => 'waiting on owner', 'deps' => ['open' => 1, 'total' => 1]])
        ->and($cards[$doing]['url'])->toBe(stackUrl(21010))
        ->and($cards[$doing]['agent'])->toMatchArray(['state' => 'working'])
        ->and($cards[$doing]['agent']['since'])->toBeGreaterThan(time() - 310)->toBeLessThan(time() - 290)
        ->and($cards[$dropped]['stage'])->toBe('dropped');
});

it('names a question apart from a plain block, and how many open cards wait on a hub', function () {
    $s = $this->sandbox;
    $asked = $s->card('Print notes', ['--label=area:print']);
    $s->ok(['set', $asked, 'blocked=question: which renderer?']);
    $held = $s->card('Rotate keys');
    $s->ok(['set', $held, 'blocked=waiting on security']);
    $hub = $s->card('Define the schema');
    $waiting = array_map(fn (int $i) => $s->card("Export {$i}", ["--depends={$hub}"]), [1, 2, 3]);
    $s->ok(['move', $waiting[2], 'dropped', '--reason=not needed']);
    $two = collect($this->getJson('/kanban/_api/work')->json('stages'))->pluck('cards')->flatten(1)->keyBy('id');
    $s->card('Export 4', ["--depends={$hub}"]);

    $cards = collect($this->getJson('/kanban/_api/work')->assertOk()->json('stages'))->pluck('cards')->flatten(1)->keyBy('id');
    expect($cards[$asked])->toMatchArray(['blocked' => 'question: which renderer?', 'question' => 'which renderer?'])
        ->and($cards[$held])->toMatchArray(['blocked' => 'waiting on security', 'question' => null])
        ->and($two[$hub]['blocks'])->toBe(0)
        ->and($cards[$hub]['blocks'])->toBe(3)
        ->and($cards[$waiting[0]]['blocks'])->toBe(0);
    $this->getJson("/kanban/_api/cards/{$asked}")->assertOk()->assertJsonPath('card.question', 'which renderer?');
});

it('reports an agent with no recent heartbeat as stale and a finished one as stopped', function () {
    $s = $this->sandbox;
    $stale = $s->readyCard('Went quiet');
    $stopped = $s->readyCard('Wrapped up');
    $transitions = new Transitions(app(Store::class));
    foreach ([$stale, $stopped] as $id) {
        $transitions->start($id, new Actor('main', 's1'), null, ['branch' => 'card/'.$id, 'stack' => null]);
    }
    touch(agent($s, 'a1', $stale), time() - 3600);
    agent($s, 'a2', $stopped, ['stopped_at' => gmdate('Y-m-d\TH:i:s.000+00:00')]);

    $cards = collect($this->getJson('/kanban/_api/work')->json('stages.3.cards'))->keyBy('id');

    expect($cards[$stale]['agent']['state'])->toBe('stale')->and($cards[$stale]['agent']['beat'])->toBeLessThan(time() - 3500)
        ->and($cards[$stopped]['agent']['state'])->toBe('stopped');
});

it('shows no agent on a card that has left the work, whatever its agent record still says', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Sent back');
    $transitions = new Transitions(app(Store::class));
    $transitions->start($id, new Actor('main', 's1'), null, ['branch' => 'card/'.$id, 'stack' => null]);
    touch(agent($s, 'a1', $id), time() - 3600);
    $transitions->stop($id, 'ready', new Actor('main', 's1'));

    $ready = collect($this->getJson('/kanban/_api/work')->json('stages.2.cards'))->keyBy('id');

    expect($ready[$id]['stage'])->toBe('ready')->and($ready[$id]['agent'])->toBeNull();
});

it('shows a WIP limit on doing and review', function () {
    $this->sandbox->ok(['board', 'work', '--wip-doing=2']);

    $stages = $this->getJson('/kanban/_api/work')->json('stages');

    expect($stages[3]['limit'])->toBe(2)->and($stages[4]['limit'])->toBeInt()->and($stages[0]['limit'])->toBeNull()->and($stages[1]['limit'])->toBeNull();
});

it('shows only the last 20 done cards unless asked for all', function () {
    $store = app(Store::class);
    $transitions = new Transitions($store);
    $main = new Actor('main', 's1');
    for ($i = 1; $i <= 21; $i++) {
        $card = $store->create(BoardRef::parse('work'), ['title' => "Shipped {$i}", 'body' => 'x', 'stage' => 'ready', 'labels' => ["area:a{$i}"],
            'acceptance' => [['id' => 1, 'text' => 'works', 'done' => false]]], $main);
        $transitions->start($card->id(), $main, null, ['branch' => 'card/'.$card->id(), 'stack' => null], force: true);
        $store->update($card->id(), fn (array $d) => Transitions::stage($d, 'review', 'apply'), $main);
        $store->update($card->id(), fn (array $d) => Transitions::stage($d, 'done', 'finish'), $main);
    }

    $done = $this->getJson('/kanban/_api/work')->json('stages.5');
    expect($done['total'])->toBe(21)->and($done['older'])->toBe(1)->and($done['cards'])->toHaveCount(20);

    $all = $this->getJson('/kanban/_api/work?all=1')->json('stages.5');
    expect($all['older'])->toBe(0)->and($all['cards'])->toHaveCount(21);
});

it('answers 304 until the board changes, and keeps the same ETag across a heartbeat', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Watch me');
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, ['branch' => 'card/x', 'stack' => null]);
    $heartbeat = agent($s, 'a1', $id);

    $first = $this->getJson('/kanban/_api/work')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->toStartWith('W/"');

    $this->getJson('/kanban/_api/work', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
    touch($heartbeat, time() + 5);
    clearstatcache();
    $this->getJson('/kanban/_api/work', ['If-None-Match' => $etag])->assertStatus(304);
    $this->getJson('/kanban/_api/boards', ['If-None-Match' => $this->getJson('/kanban/_api/boards')->headers->get('ETag')])->assertStatus(304);

    agent($s, 'a1', $id, ['stopped_at' => gmdate('Y-m-d\TH:i:s.000+00:00')]);
    $stopped = $this->getJson('/kanban/_api/work', ['If-None-Match' => $etag])->assertOk();
    expect($stopped->headers->get('ETag'))->not->toBe($etag);

    $s->ok(['set', $id, 'priority=urgent', '--force'], ['KANBAN_SESSION' => 's1']);
    $changed = $this->getJson('/kanban/_api/work', ['If-None-Match' => $stopped->headers->get('ETag')])->assertOk();
    expect($changed->headers->get('ETag'))->not->toBe($stopped->headers->get('ETag'))
        ->and(collect($changed->json('stages.3.cards'))->firstWhere('id', $id)['priority'])->toBe('urgent');
});

it('gives the same board for 300 stopped agents as for none, in the same order', function () {
    $s = $this->sandbox;
    $s->card('One');
    $s->card('Two');
    $before = $this->getJson('/kanban/_api/work')->json();
    for ($i = 0; $i < 300; $i++) {
        agent($s, "old{$i}", 'ACME-999', ['stopped_at' => gmdate('Y-m-d\TH:i:s.000+00:00')]);
    }

    expect($this->getJson('/kanban/_api/work')->json())->toBe($before);
});

it('404s an unknown board and an unknown card as JSON', function () {
    $this->getJson('/kanban/_api/nothing')->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson('/kanban/_api/cards/ACME-999')->assertNotFound()->assertJsonStructure(['message']);
});

it('describes a card in full, rendering Markdown escaped and without unsafe links', function () {
    $s = $this->sandbox;
    $dep = $s->card('Upstream');
    $id = $s->card('Rich card', ['--body=## Context'."\n\nRun <script>alert(1)</script> or [click](javascript:alert(2)) **bold**", '--accept=First', '--accept=Second', "--depends={$dep}", '--label=area:ui']);
    $s->ok(['set', $id, 'tick=2', 'note=Owner says <b>hi</b>']);

    $card = $this->getJson("/kanban/_api/cards/{$id}")->assertOk()->json('card');

    expect($card)->toMatchArray([
        'id' => $id, 'title' => 'Rich card', 'stage' => 'backlog', 'labels' => ['area:ui'],
        'acceptance' => [['id' => 1, 'text' => 'First', 'done' => false], ['id' => 2, 'text' => 'Second', 'done' => true]],
        'depends_on' => [['id' => $dep, 'title' => 'Upstream', 'stage' => 'backlog', 'satisfied' => false]],
        'board' => ['ref' => 'work', 'title' => 'Work'],
        'targets' => ['planning', 'dropped'],
        'rev' => sha1_file($s->root."/docs/kanban/work/{$id}.json"),
    ])
        ->and($card['body'])->toContain('## Context')
        ->and($card['body_html'])->toContain('<h2>Context</h2>')->toContain('&lt;script&gt;')->not->toContain('<script>')->not->toContain('javascript:')
        ->and($card['body_html'])->toContain('<a>click</a> <strong>bold</strong>')
        ->and($card)->not->toHaveKeys(['why', 'why_html', 'supersedes', 'superseded_by'])
        ->and(collect($card['log'])->firstWhere('event', 'note'))->toMatchArray(['by' => 'owner', 'text' => 'Owner says <b>hi</b>'])
        ->and($card['log_total'])->toBeGreaterThanOrEqual(2)
        ->and($card['facts'])->toHaveKeys(['created', 'updated', 'stage_since']);
});

it('describes a card\'s plan, rendered, with who made it on which commit and whether it still covers the card', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Planned');
    $base = substr(trim($s->git('rev-parse', 'main')), 0, 7);
    $unplanned = $s->card('Unplanned');

    $card = $this->getJson("/kanban/_api/cards/{$id}")->assertOk()->json('card');
    expect($card['plan_html'])->toContain('<h2>Files</h2>')->toContain('<code>README.md</code>')
        ->and($card['planned'])->toMatchArray(['base' => $base, 'current' => true])
        ->and($card['planned']['by'])->toStartWith('owner')
        ->and($this->getJson("/kanban/_api/cards/{$unplanned}")->json('card'))->toMatchArray(['plan_html' => '', 'planned' => null]);

    $s->ok(['move', $id, 'backlog']);
    $s->ok(['set', $id, 'body=Build it again']);
    expect($this->getJson("/kanban/_api/cards/{$id}")->json('card.planned.current'))->toBeFalse();
});

it('says whether a card is in a locked stage', function () {
    $s = $this->sandbox;
    $open = $s->card('Free');
    $busy = $s->readyCard('Busy');
    (new Transitions(app(Store::class)))->start($busy, new Actor('main', 's1'), null, ['branch' => 'card/busy', 'stack' => null]);

    expect($this->getJson("/kanban/_api/cards/{$open}")->json('card.locked'))->toBeFalse()
        ->and($this->getJson("/kanban/_api/cards/{$busy}")->json('card.locked'))->toBeTrue();
});

it('lists the stages kanban.json locks', function () {
    $file = $this->sandbox->root.'/docs/kanban/kanban.json';
    $config = json_decode(file_get_contents($file), true);
    $config['locked'] = ['backlog'];
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT)."\n");
    $this->sandbox->git('-C', 'docs/kanban', 'commit', '-q', '-am', 'backlog locked');

    $this->getJson('/kanban/_api/work')->assertOk()->assertJsonPath('locked', ['backlog']);
});

it('lists the newest 20 log entries and the running facts of a started card', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Busy');
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, [
        'branch' => 'card/busy', 'worktree' => '.claude/worktrees/busy',
        'stack' => ['project' => 'app-wt-busy', 'slot' => 1, 'ports' => [], 'url' => stackUrl(21020)],
    ]);
    for ($i = 1; $i <= 25; $i++) {
        $s->ok(['set', $id, "note=note {$i}"]);
    }

    $card = $this->getJson("/kanban/_api/cards/{$id}")->json('card');

    expect($card['log'])->toHaveCount(20)->and($card['log'][0]['text'])->toBe('note 25')->and($card['log_total'])->toBeGreaterThan(25)
        ->and($card['facts'])->toMatchArray(['branch' => 'card/busy', 'worktree' => '.claude/worktrees/busy'])
        ->and($card['facts']['claim'])->toHaveKeys(['by', 'at'])
        ->and($card['url'])->toBe(stackUrl(21020));
});

it('finds cards by id or title for the dependency picker', function () {
    $s = $this->sandbox;
    $a = $s->card('Refactor billing');
    $s->card('Fix login');

    $this->getJson('/kanban/_api/cards?q=billing')->assertOk()->assertJsonPath('cards.*.id', [$a])->assertJsonPath('cards.0.title', 'Refactor billing')->assertJsonPath('cards.0.stage', 'backlog');
    $this->getJson('/kanban/_api/cards?q='.substr($a, 5))->assertJsonPath('cards.0.id', $a);
    expect($this->getJson('/kanban/_api/cards')->json('cards'))->toHaveCount(2);
});

it('leaves the asking card and what it depends on out of the dependency picker, and lists id matches first', function () {
    $s = $this->sandbox;
    $asking = $s->card('Checkout flow');
    $dep = $s->card('Checkout tax');
    $other = $s->card('Refund checkout');
    $s->ok(['set', $asking, "depends_on=+{$dep}"]);

    $found = $this->getJson("/kanban/_api/cards?q=checkout&for={$asking}")->assertOk()->json('cards.*.id');

    expect($found)->toBe([$other])
        ->and($this->getJson('/kanban/_api/cards?q=checkout&for=ACME-NOPE')->json('cards'))->toHaveCount(3)
        ->and(array_keys($this->getJson('/kanban/_api/cards?q='.substr($dep, 5))->json('cards.0')))->toBe(['id', 'title', 'stage', 'board']);
    expect($this->getJson('/kanban/_api/cards?q='.substr($other, 5))->json('cards.0.id'))->toBe($other);
});

it('treats a malformed search as an empty one instead of failing', function () {
    $this->sandbox->card('Refactor billing');

    $this->getJson('/kanban/_api/cards?q[]=x')->assertOk()->assertJsonCount(1, 'cards');
    $this->getJson('/kanban/_api/cards?for[]=x')->assertOk();
});

it('lists the epics with their progress, and each card with its epic', function () {
    $s = $this->sandbox;
    $s->ok(['epic', 'exports', 'Exports', '--goal=Notes leave the app', '--done-when=CSV ships']);
    $csv = $s->card('Export as CSV');
    $s->ok(['set', $csv, 'epic=exports']);
    $s->ok(['set', $s->card('Export as Markdown'), 'epic=exports']);
    $s->card('Not in an epic');

    $this->getJson('/kanban/_api/boards')->assertOk()
        ->assertJsonPath('epics', [['slug' => 'exports', 'title' => 'Exports', 'goal' => 'Notes leave the app', 'done_when' => ['CSV ships'], 'done' => 0, 'total' => 2]]);
    $cards = collect($this->getJson('/kanban/_api/work')->json('stages.0.cards'))->keyBy('title');

    expect($cards['Export as CSV']['epic'])->toBe(['slug' => 'exports', 'title' => 'Exports'])
        ->and($cards['Not in an epic']['epic'])->toBeNull();
});

it('gives each area a colour slot in the order the areas first appeared, the same on every board', function () {
    $s = $this->sandbox;
    $s->card('Older', ['--label=area:search']);
    $s->card('Newer', ['--label=area:billing', '--label=area:search']);
    $s->ok(['board', 'infra', 'Infra']);
    $s->card('Elsewhere', ['--label=area:backups'], board: 'infra');

    expect($this->getJson('/kanban/_api/work')->json('areas'))->toBe(['area:search' => 0, 'area:billing' => 1, 'area:backups' => 2])
        ->and($this->getJson('/kanban/_api/infra')->json('areas'))->toBe(['area:search' => 0, 'area:billing' => 1, 'area:backups' => 2]);
});

it('reports a card whose epic file is missing as a board problem', function () {
    $s = $this->sandbox;
    $s->ok(['epic', 'exports', 'Exports']);
    $s->ok(['set', $s->card('Orphaned'), 'epic=exports']);
    unlink($s->root.'/docs/kanban/_epics/exports.json');

    $response = $this->getJson('/kanban/_api/boards')->assertOk();

    expect($response->json('boards.*.ref'))->toContain('work')
        ->and($response->json('notices.0'))->toContain('board files have problems');
});

it('lists agents whose records are damaged, and reports board problems with the board', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Fine');
    (new Transitions(app(Store::class)))->start($id, new Actor('main', 's1'), null, ['branch' => 'card/'.$id, 'stack' => null]);
    $broken = $s->card('Broken');
    file_put_contents($s->root."/docs/kanban/work/{$broken}.json", '{not json');
    agent($s, 'a1', $id, ['bound_at' => 'garbage', 'stopped_at' => gmdate('Y-m-d\TH:i:s.000+00:00')]);

    $response = $this->getJson('/kanban/_api/work')->assertOk();

    expect($response->json('stages.3.cards.0.agent.state'))->toBe('stopped')
        ->and($response->json('notices.0'))->toContain('board files have problems');
});

it('lists a card whose timestamps are damaged instead of failing the board', function () {
    $s = $this->sandbox;
    $id = $s->card('Odd dates');
    $file = $s->root."/docs/kanban/work/{$id}.json";
    file_put_contents($file, str_replace('"created": "', '"created": "garbage-', (string) file_get_contents($file)));

    $this->getJson('/kanban/_api/work')->assertOk()->assertJsonPath('stages.0.cards.0.id', $id);
});

it('gives the boards and the board answers one layout signature that changes with the set of boards', function () {
    $s = $this->sandbox;

    $before = $this->getJson('/kanban/_api/boards')->json('layout');
    expect($this->getJson('/kanban/_api/work')->json('layout'))->toBe($before)->and($before)->toBeString();

    $s->card('A card changes nothing');
    expect($this->getJson('/kanban/_api/boards')->json('layout'))->toBe($before);

    $s->ok(['board', 'extra', 'Extra']);
    $extra = $this->getJson('/kanban/_api/boards')->json('layout');
    expect($extra)->not->toBe($before);

    $s->ok(['epic', 'exports', 'Exports']);
    expect($this->getJson('/kanban/_api/boards')->json('layout'))->not->toBe($extra);
});

it('answers unknown boards and an unattached checkout as JSON, never as a server error', function () {
    $bare = Sandbox::create('bare2');
    UiSandbox::boot($bare->root);

    $this->getJson('/kanban/_api/work')->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson('/kanban/_api/cards?q=x')->assertNotFound()->assertJsonStructure(['message']);
});

it('gives a card whose board file is missing a board to show it under', function () {
    $s = $this->sandbox;
    $id = $s->card('Homeless');
    unlink($s->root.'/docs/kanban/work/board.json');

    $card = $this->getJson("/kanban/_api/cards/{$id}")->assertOk()->json('card');

    expect($card['board'])->toMatchArray(['ref' => 'work', 'title' => 'work'])->and($card['targets'])->toBeArray();
});

it('says where each approved card stands in the merge queue: being merged, waiting for a red main, or at its place', function () {
    $s = $this->sandbox;
    $store = app(Store::class);
    $main = new Actor('main', 's1');
    $approved = function (string $title, array $options, string $at) use ($s, $store, $main): string {
        $id = $s->readyCard($title, $options);
        (new Transitions($store))->start($id, $main, null, ['branch' => 'card/'.strtolower($id), 'stack' => null]);
        (new Transitions($store))->apply($id, $main);
        $store->update($id, function (array $data) use ($at) {
            $data['work']['approved'] = ['head' => str_repeat('a', 40), 'at' => $at];

            return $data;
        }, $main);

        return $id;
    };
    $merging = $approved('Tag notes', [], '2026-10-09T07:00:00.000+00:00');
    $fix = $approved('Fix the export test', ['--label=main-red', '--accept=`php artisan test` passes on main'], '2026-10-09T07:10:00.000+00:00');
    $held = $approved('Archive notes', [], '2026-10-09T07:20:00.000+00:00');
    $store->update($held, function (array $data) use ($fix, $s) {
        $data['log'][] = MergeState::entry('main', ['red' => $fix, 'command' => 'php artisan test', 'base' => trim($s->git('rev-parse', 'refs/heads/main'))]);

        return $data;
    }, $main);
    $store->lease(fn (?array $old) => [['id' => '9f2c41d07a8b3e65', 'card' => $merging, 'by' => 'ana@host-a', 'who' => 'Ana',
        'since' => '2026-10-09T08:00:00.000+00:00', 'beat' => '2026-10-09T08:04:00.000+00:00'], [], "merge lease {$merging} taken"], $main);

    $cards = collect($this->getJson('/kanban/_api/work')->assertOk()->json('stages'))->pluck('cards')->flatten(1)->keyBy('id');
    $facts = $this->getJson("/kanban/_api/cards/{$merging}")->json('card.facts');

    expect($cards[$merging]['merge'])->toBe(['state' => 'merging', 'by' => 'Ana', 'since' => strtotime('2026-10-09T08:00:00Z')])
        ->and($cards[$fix]['merge'])->toBe(['state' => 'queued', 'position' => 1])
        ->and($cards[$held]['merge'])->toBe(['state' => 'held', 'waits' => "waits for {$fix} (failing on main)"])
        ->and($facts['queue'])->toBe('merging on host-a (Ana) since 08:00')
        ->and($this->getJson("/kanban/_api/cards/{$fix}")->json('card.facts.queue'))->toBe('queued 1st');
});
