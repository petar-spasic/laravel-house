<?php

use App\Models\User;
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
