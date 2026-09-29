<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Horizon's default lets every request in when local; the local stack listens on the LAN,
     * so the local exception is narrowed to requests from the host machine itself.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()])
            || (app()->environment('local') && $this->comesFromHost($request)));
    }

    /**
     * Loopback, or — inside the Docker stack — the container's default gateway, which is where
     * the host's own requests to the published port arrive from.
     */
    private function comesFromHost(Request $request): bool
    {
        $hostAddresses = ['127.0.0.1', '::1'];

        if (is_file('/.dockerenv') && preg_match('/^\S+\t00000000\t([0-9A-F]{8})\t/m', (string) @file_get_contents('/proc/net/route'), $route)) {
            $hostAddresses[] = long2ip(unpack('V', hex2bin($route[1]))[1]);
        }

        return in_array($request->ip(), $hostAddresses, true);
    }

    /**
     * Register the Horizon gate: the admins in `config('auth.admins')` with a verified email, in every
     * environment. The address matches exactly (the config is lower-cased, like the addresses Fortify stores).
     * Registration is open, so an unverified address proves nothing about who holds it (app/Providers/CLAUDE.md).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user?->email_verified_at !== null && in_array($user->email, config('auth.admins', []), true);
        });
    }
}
