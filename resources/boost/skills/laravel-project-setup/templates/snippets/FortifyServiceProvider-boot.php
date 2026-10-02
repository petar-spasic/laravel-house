<?php

// Merge into app/Providers/FortifyServiceProvider.php (routes/CLAUDE.md, Fortify). Imports:
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
<!-- if:spa -->
use Illuminate\Auth\Notifications\VerifyEmail;
<!-- endif -->
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
<!-- if:spa -->
use Illuminate\Support\Facades\URL;
<!-- endif -->

// boot():

<!-- if:spa -->
        // The mailed links open the SvelteKit pages, which post to Fortify under /api/auth from the browser.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => url('/reset-password/'.$token).'?'.http_build_query([
            'email' => $user->getEmailForPasswordReset(),
        ]));

        // Used once Features::emailVerification() is on. The signature covers Fortify's own URL, so the page
        // calls /api/auth/email/verify/{id}/{hash} with this query unchanged.
        VerifyEmail::createUrlUsing(function (User $user): string {
            $id = $user->getKey();
            $hash = sha1($user->getEmailForVerification());
            $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [
                'id' => $id,
                'hash' => $hash,
            ]);

            return url("/email/verify/{$id}/{$hash}").'?'.parse_url($signed, PHP_URL_QUERY);
        });
<!-- endif -->
<!-- unless:spa -->
        // The named route `password.reset` exists only with Fortify's views on; this is its path.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => url('/reset-password/'.$token).'?'.http_build_query([
            'email' => $user->getEmailForPasswordReset(),
        ]));
<!-- endif -->

        // Fortify's own limiters (login, two-factor, passkeys; verification when on) are all it throttles; the other
        // guest POSTs get this one (config/fortify.php `middleware` applies it to every Fortify route, the closure
        // narrows it).
<!-- unless:spa -->
        RateLimiter::for('auth-forms', fn (Request $request): Limit => $request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')
            ? Limit::perMinute(5)->by($request->route()->getName().'|'.$request->ip())
            : Limit::none());
<!-- endif -->
<!-- if:spa -->
        // No limit on the e2e site (APP_E2E): its browser flows sign in back to back from one address. Fortify's
        // published login, two-factor and passkeys closures start with the same check.
        RateLimiter::for('auth-forms', fn (Request $request): Limit => ! config('app.e2e') && $request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')
            ? Limit::perMinute(5)->by($request->route()->getName().'|'.$request->ip())
            : Limit::none());
<!-- endif -->
