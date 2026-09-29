<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the dev accounts once however often db:seed runs', function () {
    $this->artisan('db:seed')->assertSuccessful();
    $this->artisan('db:seed')->assertSuccessful();

    expect(User::query()->whereIn('email', ['admin@{{app}}.test', 'dev@{{app}}.test'])->count())->toBe(2)
        ->and(User::query()->count())->toBe(2)
        ->and(User::query()->where('email', 'admin@{{app}}.test')->value('email_verified_at'))->not->toBeNull();
});

it('refuses to run the dev seeder in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => $this->artisan('db:seed', ['--class' => 'DevSeeder', '--force' => true])->run())
        ->toThrow(LogicException::class);

    expect(User::query()->count())->toBe(0);
});

it('skips the operator with a warning when its env is unset', function () {
    $this->app['env'] = 'production';
    config(['auth.admins' => [], 'auth.operator' => ['email' => null, 'password' => null]]);

    $this->artisan('db:seed', ['--force' => true])
        ->expectsOutputToContain('no operator account seeded')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});
