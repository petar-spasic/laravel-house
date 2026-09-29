<?php

use Illuminate\Support\Facades\Route;
use PetarSpasic\Kanban\Tests\Support\Sandbox;
use PetarSpasic\Kanban\Tests\Support\UiSandbox;

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

it('serves only its two assets, immutable when versioned', function () {
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

    $stale = $this->get('/kanban/assets/kanban.js?v=old')->assertOk();
    expect($stale->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('immutable');

    $this->get('/kanban/assets/app.js')->assertNotFound();
    $this->get('/kanban/assets/..%2F..%2Fcomposer.json')->assertNotFound();
    $this->get('/kanban/assets/kanban.css.map')->assertNotFound();
    expect(is_dir($s->root.'/public'))->toBeFalse();
});

it('asks for the token when one is configured and remembers it in a cookie', function () {
    $s = Sandbox::create();
    $s->install('ACME');
    UiSandbox::boot($s->root, config: ['kanban.ui.token' => 's3cret']);

    $this->get('/kanban')->assertForbidden();
    $this->get('/kanban?token=wrong')->assertForbidden();
    $this->get('/kanban', ['X-Kanban-Token' => 's3cret'])->assertOk();
    $this->get('/kanban?token=s3cret')->assertOk()->assertCookie('kanban_token');
});
