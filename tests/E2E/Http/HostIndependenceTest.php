<?php

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    UiSandbox::boot($this->sandbox->root);
    Route::post('/kanban/_api/probe', fn () => response()->json(['ok' => true]))->middleware('kanban')->name('kanban.api.probe');
    Route::get('/kanban/_api/probe', fn () => response()->json(['ok' => true]))->middleware('kanban')->name('kanban.api.probe.read');
});

it('runs outside the host web middleware group: no session, cookie or token is created', function () {
    foreach (['/kanban', '/kanban/project/work', '/kanban/cards/ACME-1', '/kanban/_api/probe'] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->headers->getCookies())->toBe([], $url)
            ->and($response->getContent())->not->toContain('csrf-token');
    }
    expect(config('kanban.ui.middleware'))->toBe([])
        ->and(Route::getMiddlewareGroups()['kanban'])->not->toContain('web');
});

it('serves the same shell page for the boards, a board and a card', function () {
    $index = $this->get('http://localhost/kanban')->assertOk()->getContent();

    expect($this->get('/kanban/project/work')->getContent())->toBe($index)
        ->and($this->get('/kanban/cards/ACME-1')->getContent())->toBe($index)
        ->and($index)->toContain('data-base="/kanban"')->toContain('data-poll-ms="3000"');
});

it('answers API calls as JSON even when the client does not ask for it', function () {
    $this->get('/kanban/_api/nothing', ['Accept' => 'text/html'])->assertNotFound()->assertJson(['message' => 'Not found']);
});

it('refuses cross-site requests, except a plain navigation to the shell page', function () {
    $this->get('/kanban/_api/probe', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'cors'])->assertForbidden();
    $this->get('/kanban/_api/probe', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors'])->assertOk();
    $this->get('/kanban/project/work', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate'])->assertOk();
    $this->get('/kanban/project/work', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'no-cors'])->assertForbidden();
    $this->post('/kanban/_api/probe', [], ['X-Kanban' => '1', 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate'])->assertForbidden();
});

it('needs the X-Kanban header on writes, which a cross-site form cannot send', function () {
    $this->post('/kanban/_api/probe')->assertForbidden()->assertJson(['message' => 'Kanban writes need the X-Kanban header']);
    $this->post('/kanban/_api/probe', [], ['X-Kanban' => '1'])->assertOk();
});

it('answers only to the machine\'s own names, so a page that rebinds its DNS name to this machine gets nothing', function () {
    $this->get('http://rebind.example/kanban/_api/probe')->assertForbidden();
    $this->get('http://rebind.example/kanban')->assertForbidden();
    $this->post('http://rebind.example/kanban/_api/probe', [], ['X-Kanban' => '1', 'Origin' => 'http://rebind.example'])->assertForbidden();
    foreach (['localhost', '198.18.0.7', '[2001:db8::1]', 'acme-notes.test', 'app.localhost'] as $host) {
        $this->get("http://{$host}/kanban/_api/probe")->assertOk();
    }

    config(['app.url' => 'http://acme.dev-box.lan:8080', 'kanban.ui.hosts' => ['worktree.example']]);

    $this->get('http://acme.dev-box.lan:8080/kanban/_api/probe')->assertOk();
    $this->get('http://worktree.example/kanban/_api/probe')->assertOk();
    $this->get('http://rebind.example/kanban/_api/probe')->assertForbidden();
});

it('reads the Host the client sent, not the one a proxy header claims', function () {
    TrustProxies::at('*');
    try {
        $this->get('http://rebind.example/kanban/_api/probe', ['X-Forwarded-Host' => 'localhost'])->assertForbidden();
    } finally {
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
    }
});

it('answers to the LOCAL_APP_URL host even when a published config predates the hosts key', function () {
    Env::getRepository()->set('LOCAL_APP_URL', 'http://worktree-b.example:8011');
    try {
        UiSandbox::boot($this->sandbox->root, config: ['kanban.ui.hosts' => null]);

        $this->get('http://worktree-b.example:8011/kanban/_api/probe')->assertOk();
        $this->get('http://rebind.example/kanban/_api/probe')->assertForbidden();
    } finally {
        Env::getRepository()->clear('LOCAL_APP_URL');
    }
});

it('sends a policy that lets the page load nothing from elsewhere, be framed or leak a Referer', function () {
    foreach (['/kanban', '/kanban/_api/probe', '/kanban/assets/kanban.js'] as $path) {
        $response = $this->get($path)->assertOk();
        expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    }
});

it('accepts a write from the page when a proxy hides the scheme or the port', function () {
    $write = fn (string $uri, string $origin) => $this->post($uri.'/kanban/_api/probe', [], ['X-Kanban' => '1', 'Origin' => $origin]);

    $write('http://localhost', 'https://localhost')->assertOk();
    $write('http://localhost', 'http://localhost:8080')->assertOk();
    $write('http://localhost:8080', 'http://localhost:8080')->assertOk();
    $write('http://localhost:8080', 'http://localhost:3000')->assertForbidden();
    $write('http://localhost', 'http://evil.example')->assertForbidden();
});

it('keeps the token gate without a session', function () {
    UiSandbox::boot($this->sandbox->root, config: ['kanban.ui.token' => 's3cret']);

    $this->get('/kanban/_api/probe')->assertUnauthorized();
    $this->get('/kanban/_api/probe', ['X-Kanban-Token' => 's3cret'])->assertOk();
    $response = $this->get('/kanban?token=s3cret')->assertRedirect('/kanban');
    $cookie = $response->headers->getCookies()[0];
    expect($cookie->getName())->toBe('kanban_token')->and($cookie->getValue())->toBe('s3cret')->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 300 * 86400);
    $this->withUnencryptedCookie('kanban_token', 's3cret')->get('/kanban/_api/probe')->assertOk();
});

it('drops `web` from a published config that still lists it, so writes need no CSRF token', function () {
    UiSandbox::boot($this->sandbox->root, config: ['kanban.ui.middleware' => ['web', 'throttle:60,1']]);

    expect(Route::getMiddlewareGroups()['kanban'])->not->toContain('web')->toContain('throttle:60,1');
});

it('draws its links from the app\'s own URL, prefix included', function () {
    URL::forceRootUrl('http://localhost/sub');

    $page = $this->get('http://localhost/kanban')->assertOk()->getContent();

    expect($page)->toContain('data-base="/sub/kanban"')->and(html_entity_decode($page))->toContain('href="http://localhost/sub/kanban/assets/kanban.css');
});
