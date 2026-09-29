<?php

// Merge into app/Providers/FortifyServiceProvider.php while config/fortify.php 'views' is false. Imports:
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

// boot():

        // The named route `password.reset` exists only with Fortify's views on; this is its path.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => url('/reset-password/'.$token).'?'.http_build_query([
            'email' => $user->getEmailForPasswordReset(),
        ]));
