<?php

use Illuminate\Support\Facades\Route;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

it('registers no routes outside the local environment', function () {
    $s = Sandbox::create();
    UiSandbox::boot($s->root, 'production');

    expect(Route::has('kanban.index'))->toBeFalse();
    $this->get('/kanban')->assertNotFound();
});

it('registers no routes when the app runs from a linked worktree (.git is a file)', function () {
    $s = Sandbox::create();
    $worktree = Sandbox::tmp().'/wt';
    $s->git('worktree', 'add', '-q', '-b', 'card/x', $worktree);
    expect(is_file($worktree.'/.git'))->toBeTrue();

    UiSandbox::boot($worktree);

    expect(Route::has('kanban.index'))->toBeFalse();
    $this->get('/kanban')->assertNotFound();
});

it('registers no routes when disabled in config', function () {
    $s = Sandbox::create();
    UiSandbox::boot($s->root, config: ['kanban.ui.enabled' => false]);

    expect(Route::has('kanban.index'))->toBeFalse();
});

it('serves only its own assets: immutable when versioned, fonts always', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    UiSandbox::boot($s->root);
    $package = Sandbox::package();

    $page = $this->get('/kanban')->assertOk()->getContent();
    preg_match('#<link rel="stylesheet" href="([^"]+)">#', $page, $css);
    preg_match('#<script src="([^"]+)" defer></script>#', $page, $js);
    expect(html_entity_decode($css[1]))->toBe(route('kanban.asset', ['asset' => 'kanban.css', 'v' => sha1_file($package.'/resources/dist/kanban.css')]))
        ->and(html_entity_decode($js[1]))->toBe(route('kanban.asset', ['asset' => 'kanban.js', 'v' => sha1_file($package.'/resources/dist/kanban.js')]));

    $response = $this->get(html_entity_decode($css[1]))->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')->toContain('public')
        ->and($response->getContent())->toBe(file_get_contents($package.'/resources/dist/kanban.css'));
    $this->get(html_entity_decode($js[1]))->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    preg_match('#<link rel="icon" href="([^"]+)" type="image/svg\+xml">#', $page, $icon);
    expect(html_entity_decode($icon[1]))->toBe(route('kanban.asset', ['asset' => 'kanban.svg', 'v' => sha1_file($package.'/resources/dist/kanban.svg')]));
    $this->get(html_entity_decode($icon[1]))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $fonts = glob($package.'/resources/dist/inter-*-*.woff2');
    expect($fonts)->toHaveCount(3);
    foreach ($fonts as $file) {
        $font = route('kanban.asset', ['asset' => basename($file)]);
        expect($page)->toContain('<link rel="preload" href="'.$font.'" as="font" type="font/woff2" crossorigin>');
        $response = $this->get($font)->assertOk()->assertHeader('Content-Type', 'font/woff2');
        expect($response->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')
            ->and($response->getContent())->toBe(file_get_contents($file));
    }

    $stale = $this->get('/kanban/assets/kanban.js?v=old')->assertOk();
    expect($stale->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('immutable');

    $this->get('/kanban/assets/app.js')->assertNotFound();
    $this->get('/kanban/assets/inter-0-700.woff2')->assertNotFound();
    $this->get('/kanban/assets/inter-LICENSE.txt')->assertNotFound();
    $this->get('/kanban/assets/..%2F..%2Fcomposer.json')->assertNotFound();
    $this->get('/kanban/assets/kanban.css.map')->assertNotFound();
    expect(is_dir($s->root.'/public'))->toBeFalse();
});

it('asks for the token when one is configured and remembers it in a cookie', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    UiSandbox::boot($s->root, config: ['kanban.ui.token' => 's3cret']);

    $this->get('/kanban/project/work?from=ACME-1')->assertStatus(401)
        ->assertSee('name="token"', false)->assertSee('name="from" value="ACME-1"', false)->assertDontSee('did not work')
        ->assertHeader('Content-Security-Policy');
    $this->get('/kanban?token=wrong')->assertStatus(401)->assertSee('did not work');
    $this->getJson('/kanban/_api/boards')->assertStatus(401)->assertJson(['message' => 'Kanban UI token required']);
    $this->get('/kanban/assets/kanban.css')->assertOk();

    $this->get('/kanban/project/work?from=ACME-1&token=s3cret')
        ->assertStatus(303)->assertHeader('Location', '/kanban/project/work?from=ACME-1')->assertCookie('kanban_token');
    $this->withUnencryptedCookie('kanban_token', 's3cret')->get('/kanban')->assertOk();
    $this->withUnencryptedCookie('kanban_token', 'old')->get('/kanban')->assertStatus(401)->assertSee('did not work');
    $this->getJson('/kanban/_api/boards', ['X-Kanban-Token' => 's3cret'])->assertOk();
});

it('refuses a foreign host before it asks for the token', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    UiSandbox::boot($s->root, config: ['kanban.ui.token' => 's3cret']);

    $this->get('http://rebind.example/kanban')->assertForbidden()->assertDontSee('name="token"', false);
});
