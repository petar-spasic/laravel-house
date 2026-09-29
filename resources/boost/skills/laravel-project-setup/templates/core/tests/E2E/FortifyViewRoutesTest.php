<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('answers GET /login and /register without a server error while the auth views are off', function () {
    expect(Route::has('login'))->toBeFalse()
        ->and(Route::has('register'))->toBeFalse();

    $this->get('/login')->assertMethodNotAllowed();
    $this->get('/register')->assertMethodNotAllowed();
});

it('registers, signs out and signs in through the form endpoints, answering in JSON', function () {
    $this->post(route('register.store'), [
        'name' => 'Ana Anić',
        'email' => 'ana@{{app}}.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertCreated();

    $this->assertAuthenticatedAs(User::query()->where('email', 'ana@{{app}}.test')->sole());

    $this->post(route('logout'))->assertNoContent();
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => 'ana@{{app}}.test',
        'password' => 'correct-horse-battery',
    ])->assertOk()->assertExactJson(['two_factor' => false]);
    $this->assertAuthenticated();

    $this->post(route('two-factor.enable'))->assertStatus(423)->assertJsonPath('message', 'Password confirmation required.');
});

it('answers a browser guest on an authenticated auth route with 401, not a redirect to a missing login page', function () {
    $this->get(route('two-factor.qr-code'))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->get(route('password.confirmation'))->assertUnauthorized();
});

it('mails a working password reset link from the forgot-password form', function () {
    User::factory()->create(['email' => 'ana@{{app}}.test']);

    $this->post(route('password.email'), ['email' => 'ana@{{app}}.test'])
        ->assertOk()
        ->assertJsonPath('message', trans('passwords.sent'));

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    $body = html_entity_decode($messages->sole()->getOriginalMessage()->getHtmlBody());
    expect(preg_match('#'.preg_quote(url('/reset-password'), '#').'/([^?"\s]+)\?email=ana%40{{app}}\.test#', $body, $link))->toBe(1);

    $this->post(route('password.update'), [
        'token' => $link[1],
        'email' => 'ana@{{app}}.test',
        'password' => 'new-horse-battery-staple',
        'password_confirmation' => 'new-horse-battery-staple',
    ])->assertOk();

    $this->post(route('login.store'), ['email' => 'ana@{{app}}.test', 'password' => 'new-horse-battery-staple'])->assertOk();
    $this->assertAuthenticated();
});

it('challenges a two-factor user signing in through the form instead of redirecting to a missing page', function () {
    $user = User::factory()->create(['email' => 'ana@{{app}}.test', 'password' => 'correct-horse-battery']);
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login.store'), ['email' => 'ana@{{app}}.test', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertExactJson(['two_factor' => true]);
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong-code'])->assertUnprocessable();
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertNoContent();
    $this->assertAuthenticatedAs($user);
});
