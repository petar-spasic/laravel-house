<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a registered user enable two-factor and stores the secret', function () {
    $this->post(route('register.store'), [
        'name' => 'Ana Anić',
        'email' => 'ana@{{app}}.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertCreated();

    $this->postJson(route('password.confirm.store'), ['password' => 'correct-horse-battery'])->assertCreated();

    $this->postJson(route('two-factor.enable'))->assertOk();

    $secretKey = $this->getJson(route('two-factor.secret-key'))->assertOk()->json('secretKey');
    $user = User::query()->where('email', 'ana@{{app}}.test')->sole();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and(decrypt($user->two_factor_secret))->toBe($secretKey)
        ->and($user->recoveryCodes())->toHaveCount(8)
        ->and($user->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);

    $this->getJson(route('passkey.registration-options'))->assertOk()->assertJsonPath('options.user.name', 'ana@{{app}}.test');
});
