<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use PetarSpasic\Kanban\Tests\Support\Sandbox;
use PetarSpasic\Kanban\Tests\Support\UiSandbox;

/** Laravel 13 renamed the CSRF middleware. */
function csrfMiddleware(): string
{
    return class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : ValidateCsrfToken::class;
}

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    UiSandbox::boot($this->sandbox->root);
    $this->withoutMiddleware(csrfMiddleware());
});

function cardFile(Sandbox $s, string $id): string
{
    return (string) file_get_contents($s->root."/docs/kanban/project/work/{$id}.json");
}

function rev(Sandbox $s, string $id): string
{
    return sha1(cardFile($s, $id));
}

it('answers the columns fragment with a weak ETag and 304 until the board changes', function () {
    $s = $this->sandbox;
    $id = $s->card('Watch me');

    $first = $this->get('/kanban/project/work/columns')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->toStartWith('W/"')
        ->and(ltrim($first->getContent()))->toStartWith('<div class="cols" data-columns')
        ->and($first->getContent())->not->toContain('<html');

    $this->get('/kanban/project/work/columns', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
    $this->get('/kanban/project/work/columns', ['If-None-Match' => $etag])->assertStatus(304);

    $s->ok(['set', $id, 'priority=urgent']);

    $changed = $this->get('/kanban/project/work/columns', ['If-None-Match' => $etag])->assertOk();
    expect($changed->headers->get('ETag'))->not->toBe($etag)
        ->and($changed->getContent())->toContain('tile p-urgent');
});

it('moves a card, commits it on the kanban branch as owner and redirects back', function () {
    $s = $this->sandbox;
    $id = $s->card('Promote me', ['--body=Build it', '--accept=It works']);
    $board = route('kanban.board', ['epic' => 'project', 'board' => 'work']);

    $this->from($board)->post("/kanban/cards/{$id}/stage", ['to' => 'ready', 'rev' => rev($s, $id)])
        ->assertStatus(303)
        ->assertRedirect($board)
        ->assertSessionHas('kanban.notice', "{$id} moved to ready");

    $card = $s->read($id);
    expect($card['stage'])->toBe('ready')
        ->and(end($card['log']))->toMatchArray(['by' => 'owner', 'event' => 'stage', 'from' => 'backlog', 'to' => 'ready', 'via' => 'promote'])
        ->and($s->boardLog()[0])->toBe("{$id} stage backlog→ready [owner]")
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('');

    $this->get($board)->assertSee("{$id} moved to ready");
});

it('answers a fragment request with the fresh columns', function () {
    $s = $this->sandbox;
    $id = $s->readyCard('Back to backlog');

    $response = $this->post("/kanban/cards/{$id}/stage", ['to' => 'backlog', 'rev' => rev($s, $id)], ['X-Kanban' => 'fragment'])->assertOk();

    expect(ltrim($response->getContent()))->toStartWith('<div class="cols" data-columns')
        ->and($response->headers->get('ETag'))->toStartWith('W/"')
        ->and($s->read($id)['stage'])->toBe('backlog')
        ->and($s->boardLog()[0])->toBe("{$id} stage ready→backlog [owner]");
    expect($response->getContent())->toMatch('/data-stage="backlog".*data-card="'.$id.'"/s');
});

it('refuses a move the ready policy rejects with 422 and leaves the file alone', function () {
    $s = $this->sandbox;
    $id = $s->card('Bare');
    $before = cardFile($s, $id);
    $commits = count($s->boardLog());

    $this->post("/kanban/cards/{$id}/stage", ['to' => 'ready', 'rev' => sha1($before)])
        ->assertStatus(422)
        ->assertSee('R3 empty body')
        ->assertSee('R4 no acceptance criteria');
    $this->post("/kanban/cards/{$id}/stage", ['to' => 'ready', 'rev' => sha1($before)], ['X-Kanban' => 'fragment'])
        ->assertStatus(422)
        ->assertSee('<div class="notice notice-e" role="alert">', false)
        ->assertSee('R3 empty body');
    $this->post("/kanban/cards/{$id}/stage", ['to' => 'dropped', 'rev' => sha1($before)])
        ->assertStatus(422)
        ->assertSee('dropping needs a reason');

    expect(cardFile($s, $id))->toBe($before)
        ->and(count($s->boardLog()))->toBe($commits);
});

it('refuses doing, review and done as CLI only', function (string $to) {
    $s = $this->sandbox;
    $id = $s->readyCard('Not from the UI');
    $before = cardFile($s, $id);

    $this->post("/kanban/cards/{$id}/stage", ['to' => $to, 'rev' => sha1($before)])
        ->assertStatus(422)
        ->assertSee('CLI only');

    expect(cardFile($s, $id))->toBe($before);
})->with(['doing', 'review', 'done']);

it('answers a stale rev with 409 and the fresh card', function () {
    $s = $this->sandbox;
    $id = $s->card('Contested');
    $stale = rev($s, $id);
    $s->ok(['set', $id, 'title=Changed on the CLI']);
    $fresh = cardFile($s, $id);

    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'high', 'rev' => $stale])
        ->assertStatus(409)
        ->assertSee('changed since it was read')
        ->assertSee('Changed on the CLI')
        ->assertSee('value="'.sha1($fresh).'"', false);
    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'high', 'rev' => $stale], ['X-Kanban' => 'fragment'])
        ->assertStatus(409)
        ->assertSee('data-detail-card="'.$id.'"', false)
        ->assertDontSee('<html', false);

    expect(cardFile($s, $id))->toBe($fresh);
});

it('refuses a stage change posted with a stale rev, promote included', function (string $to) {
    $s = $this->sandbox;
    $id = $s->card('Raced', ['--body=Build it', '--accept=It renders']);
    $stale = rev($s, $id);
    $s->ok(['set', $id, 'title=Moved on the CLI']);
    $fresh = cardFile($s, $id);

    $this->post("/kanban/cards/{$id}/stage", ['to' => $to, 'reason' => 'not needed', 'rev' => $stale])
        ->assertStatus(409)
        ->assertSee('changed since it was read');

    expect(cardFile($s, $id))->toBe($fresh);
})->with(['ready', 'dropped']);

it('sets priority, blocks, unblocks and adds notes as owner', function () {
    $s = $this->sandbox;
    $id = $s->card('Tune me');

    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'urgent', 'rev' => rev($s, $id)])->assertStatus(303);
    expect($s->read($id)['priority'])->toBe('urgent')
        ->and($s->boardLog()[0])->toBe("{$id} set priority [owner]");

    $this->post("/kanban/cards/{$id}/blocked", ['reason' => 'needs a decision', 'rev' => rev($s, $id)])->assertStatus(303);
    expect($s->read($id)['blocked'])->toBe('needs a decision');

    $this->post("/kanban/cards/{$id}/blocked", ['reason' => '', 'rev' => rev($s, $id)])->assertStatus(303);
    expect($s->read($id)['blocked'])->toBeNull();

    $this->post("/kanban/cards/{$id}/notes", ['text' => 'Owner says <b>hi</b>', 'rev' => rev($s, $id)])->assertStatus(303);
    $card = $s->read($id);
    expect(end($card['log']))->toMatchArray(['by' => 'owner', 'event' => 'note', 'text' => 'Owner says <b>hi</b>'])
        ->and($s->boardLog()[0])->toBe("{$id} note [owner]");

    $this->get("/kanban/cards/{$id}")->assertOk()->assertSee('<q>Owner says &lt;b&gt;hi&lt;/b&gt;</q>', false);
});

it('rejects invalid input with 422 for fragments and a redirect with errors otherwise', function () {
    $s = $this->sandbox;
    $id = $s->card('Validate me');
    $before = cardFile($s, $id);

    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'whenever', 'rev' => sha1($before)], ['X-Kanban' => 'fragment'])
        ->assertStatus(422)
        ->assertSee('Not saved');
    $this->post("/kanban/cards/{$id}/notes", ['text' => '', 'rev' => 'nope'])
        ->assertStatus(302)
        ->assertSessionHasErrors(['text', 'rev']);

    expect(cardFile($s, $id))->toBe($before);
});

it('keeps CSRF protection on: a post without the page\'s token is refused', function () {
    $s = $this->sandbox;
    $id = $s->card('Forged');
    $before = cardFile($s, $id);
    $this->withMiddleware(csrfMiddleware());

    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'urgent', 'rev' => sha1($before)])->assertStatus(419);

    $page = $this->get("/kanban/cards/{$id}")->assertOk();
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page->getContent(), $m);
    $this->post("/kanban/cards/{$id}/priority", ['priority' => 'urgent', 'rev' => sha1($before)], ['X-CSRF-TOKEN' => $m[1]])->assertStatus(303);

    expect($s->read($id)['priority'])->toBe('urgent');
});
