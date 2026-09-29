<?php

use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lanClientAddress(): string
{
    $host = parse_url((string) env('LOCAL_APP_URL'), PHP_URL_HOST);

    $isLanIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;

    return $isLanIp ? $host : '192.0.2.57';
}

beforeEach(function () {
    $this->app['env'] = 'local';
    config(['auth.admins' => ['admin@{{app}}.test']]);
});

it('refuses /horizon to a guest on the LAN in local', function () {
    $this->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertForbidden();
});

it('refuses /horizon to a non-admin on the LAN in local', function () {
    $this->actingAs(User::factory()->create(['email' => 'dev@{{app}}.test']))
        ->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertForbidden();
});

it('refuses /horizon to an admin address whose email is unverified', function () {
    $this->actingAs(User::factory()->unverified()->create(['email' => 'admin@{{app}}.test']))
        ->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertForbidden();
});

it('opens /horizon to a guest on the host itself in local', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/horizon')
        ->assertOk();
});

it('opens /horizon to admin@{{app}}.test from the LAN', function () {
    $this->actingAs(User::factory()->create(['email' => 'admin@{{app}}.test']))
        ->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertOk()
        ->assertSee('Horizon');
});

it('refuses /horizon to an address that only differs in case from an admin', function () {
    $user = User::factory()->make();
    $user->setRawAttributes([...$user->getAttributes(), 'email' => 'Admin@{{app}}.test']);

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertForbidden();
});

it('opens /horizon to a second ADMIN_EMAILS address after ProductionSeeder', function () {
    config([
        'auth.admins' => ['admin@{{app}}.test', 'second@{{app}}.test'],
        'auth.operator' => ['email' => null, 'password' => null],
    ]);

    $this->seed(ProductionSeeder::class);
    $this->seed(ProductionSeeder::class);

    $second = User::query()->where('email', 'second@{{app}}.test')->firstOrFail();

    expect($second->email_verified_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(2);

    $this->actingAs($second)
        ->withServerVariables(['REMOTE_ADDR' => lanClientAddress()])
        ->get('/horizon')
        ->assertOk()
        ->assertSee('Horizon');
});
