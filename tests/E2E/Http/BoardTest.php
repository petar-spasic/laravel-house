<?php

use PetarSpasic\Kanban\Policy\Transitions;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Tests\Support\Sandbox;
use PetarSpasic\Kanban\Tests\Support\UiSandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    UiSandbox::boot($this->sandbox->root);
});

it('lists epics and boards with per-stage counts, uncached and unindexed', function () {
    $s = $this->sandbox;
    $s->card('Parked');
    $s->readyCard('Next up');
    $s->card('Workspace per team', board: 'project/decisions');

    $response = $this->get('/kanban');

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="csrf-token" content="', false)
        ->assertSee('href="'.route('kanban.board', ['epic' => 'project', 'board' => 'work']).'"', false)
        ->assertSee('href="'.route('kanban.board', ['epic' => 'project', 'board' => 'decisions']).'"', false)
        ->assertSeeInOrder(['<dt>backlog</dt><dd>1</dd>', '<dt>ready</dt><dd>1</dd>', '<dt>doing</dt><dd>0</dd>'], false)
        ->assertSeeInOrder(['<dt>proposed</dt><dd>1</dd>', '<dt>decided</dt><dd>0</dd>'], false)
        ->assertDontSee('<script>', false)
        ->assertDontSee('style=', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('notices a board that is not attached on this machine', function () {
    $bare = Sandbox::create('bare');
    UiSandbox::boot($bare->root);

    $this->get('/kanban')->assertOk()->assertSee('No board at docs/kanban on this machine: run `vendor/bin/kanban attach`', false);
});

it('renders the board kind\'s stages as columns with tiles, forms and worker state', function () {
    $s = $this->sandbox;
    $backlog = $s->card('Write <b>docs</b>', ['--priority=high', '--label=area:docs']);
    $dep = $s->card('Upstream');
    $ready = $s->readyCard('Blocked one', ["--depends={$dep}"]);
    $s->ok(['set', $ready, 'blocked=waiting on owner']);
    $doing = $s->readyCard('In flight');
    (new Transitions(app(Store::class)))->start($doing, new Actor('main', 's1'), null, [
        'branch' => 'card/x', 'stack' => ['project' => 'app-wt-x', 'slot' => 1, 'ports' => [], 'url' => 'http://localhost:21010'],
    ]);
    @mkdir($s->root.'/.git/laravel-kanban/agents', 0775, true);
    file_put_contents($s->root.'/.git/laravel-kanban/agents/a1.json', json_encode(['agent_id' => 'a1', 'agent_type' => 'kanban-worker', 'card' => $doing, 'worktree' => null, 'bound_at' => gmdate('Y-m-d\TH:i:s.000+00:00', time() - 300), 'stopped_at' => null, 'stop_blocks' => 0]));
    $dropped = $s->card('Abandoned');
    $s->ok(['move', $dropped, 'dropped', '--reason=no longer needed']);

    $response = $this->get('/kanban/project/work')->assertOk();

    $response->assertSeeInOrder(['data-stage="backlog"', 'data-stage="ready"', 'data-stage="doing"', 'data-stage="review"', 'data-stage="done"', '<details class="col col-x" data-stage="dropped"'], false)
        ->assertSee('data-columns-url="'.route('kanban.columns', ['epic' => 'project', 'board' => 'work']).'"', false)
        ->assertSee('Write &lt;b&gt;docs&lt;/b&gt;', false)
        ->assertSee('area:docs')
        ->assertSee('title="waiting on owner">blocked</span>', false)
        ->assertSee('deps 1')
        ->assertSee('working 5m')
        ->assertSee('href="http://localhost:21010"', false)
        ->assertSee('action="'.route('kanban.card.stage', $backlog).'"', false)
        ->assertSee('<select id="st-'.$backlog.'" name="to" data-autosubmit>', false)
        ->assertSee('data-rev="'.sha1_file(glob($s->root."/docs/kanban/project/work/{$backlog}.json")[0]).'"', false)
        ->assertSee('data-detail="'.route('kanban.card', ['card' => $backlog, 'fragment' => 1]).'"', false);
});

it('shows only the last 20 done cards', function () {
    $store = app(Store::class);
    $transitions = new Transitions($store);
    $main = new Actor('main', 's1');
    for ($i = 1; $i <= 21; $i++) {
        $card = $store->create(BoardRef::parse('project/work'), ['title' => "Shipped {$i}", 'body' => 'x', 'stage' => 'ready',
            'acceptance' => [['id' => 1, 'text' => 'works', 'done' => false]]], $main);
        $transitions->start($card->id(), $main, null, ['branch' => 'card/x'], true);
        $transitions->apply($card->id(), $main);
        $transitions->finish($card->id(), str_repeat('a', 40), $main);
    }

    $this->get('/kanban/project/work')->assertOk()->assertSee('1 older not shown');
});

it('404s an unknown board and an unknown card', function () {
    $this->get('/kanban/project/nope')->assertNotFound();
    $this->get('/kanban/cards/ACME-ZZZZZZ')->assertNotFound();
});

it('renders Markdown in body and why, escaped, without unsafe links', function () {
    $s = $this->sandbox;
    $id = $s->card('Script test', ['--body=## Context'."\n\n".'Run <script>alert(1)</script> or [click](javascript:alert(2)) **bold**']);
    $decision = $s->card('Decide', board: 'project/decisions');
    $s->ok(['set', $decision, 'why=Because <img src=x onerror=alert(3)>']);

    $page = $this->get("/kanban/cards/{$id}")->assertOk();
    $page->assertSee('<h2>Context</h2>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('<a>click</a> <strong>bold</strong>', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('javascript:', false);

    $this->get("/kanban/cards/{$decision}?fragment=1")->assertOk()
        ->assertDontSee('<html', false)
        ->assertSee('<h2 class="sub">Why</h2>', false)
        ->assertSee('&lt;img src=x onerror=alert(3)&gt;', false)
        ->assertDontSee('<img', false);
});
