<?php

// Merge these keys into the array config/auth.php returns.

return [

    /*
    |--------------------------------------------------------------------------
    | Admins and the production operator
    |--------------------------------------------------------------------------
    |
    | Admins are an email allow-list until roles exist (the viewHorizon gate,
    | which also requires a verified email: registration is open).
    | ProductionSeeder creates each missing admin as a verified user, and the
    | operator when missing.
    |
    */

    'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', ''))))),

    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
        'password' => env('OPERATOR_PASSWORD'),
    ],

];
