<?php

// Merge into app/Providers/FortifyServiceProvider.php while config/fortify.php 'views' is false. Imports:
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// boot():

        // The named route `password.reset` exists only with Fortify's views on; this is its path.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => url('/reset-password/'.$token).'?'.http_build_query([
            'email' => $user->getEmailForPasswordReset(),
        ]));

        // Fortify throttles only what the published config/fortify.php `limiters` names (login, two-factor, passkeys)
        // and email verification; the other guest POSTs get this one (config/fortify.php `middleware` applies it to
        // every Fortify route, the closure narrows it).
        RateLimiter::for('auth-forms', fn (Request $request): Limit => $request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')
            ? Limit::perMinute(5)->by($request->route()->getName().'|'.$request->ip())
            : Limit::none());
