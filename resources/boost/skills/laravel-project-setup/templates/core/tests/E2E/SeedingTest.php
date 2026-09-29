<?php

use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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

it('keeps OPERATOR_PASSWORD when the operator address is also an admin', function () {
    config([
        'auth.admins' => ['operator@{{app}}.test'],
        'auth.operator' => ['email' => 'operator@{{app}}.test', 'password' => 'operator-secret'],
    ]);

    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    $operator = User::query()->sole();

    expect(Hash::check('operator-secret', $operator->password))->toBeTrue()
        ->and($operator->email_verified_at)->not->toBeNull();
});

it('never modifies an existing unverified admin account and warns about it', function () {
    config(['auth.admins' => ['admin@{{app}}.test'], 'auth.operator' => ['email' => null, 'password' => null]]);

    $existing = User::factory()->unverified()->create(['email' => 'admin@{{app}}.test']);

    $this->artisan('db:seed', ['--class' => ProductionSeeder::class])
        ->expectsOutputToContain('has an unverified account')
        ->assertSuccessful();

    $admin = User::query()->sole();

    expect($admin->is($existing))->toBeTrue()
        ->and($admin->email_verified_at)->toBeNull()
        ->and($admin->password)->toBe($existing->password)
        ->and(User::query()->count())->toBe(1);
});

it('warns about an existing unverified operator account and leaves it as it is', function () {
    config(['auth.admins' => [], 'auth.operator' => ['email' => 'operator@{{app}}.test', 'password' => 'operator-secret']]);

    $existing = User::factory()->unverified()->create(['email' => 'operator@{{app}}.test']);

    $this->artisan('db:seed', ['--class' => ProductionSeeder::class])
        ->expectsOutputToContain('has an unverified account')
        ->assertSuccessful();

    $operator = User::query()->sole();

    expect($operator->email_verified_at)->toBeNull()
        ->and($operator->password)->toBe($existing->password);
});

it('seeds no operator when OPERATOR_PASSWORD is not a plain string', function () {
    config(['auth.admins' => [], 'auth.operator' => ['email' => 'operator@{{app}}.test', 'password' => true]]);

    $this->artisan('db:seed', ['--class' => ProductionSeeder::class])
        ->expectsOutputToContain('no operator account seeded')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});
