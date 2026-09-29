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
    | ProductionSeeder creates the operator first (an address that is also an
    | admin keeps OPERATOR_PASSWORD), then each missing admin, as verified
    | users; an existing account is never modified. Both addresses are
    | lower-cased: Fortify lower-cases the email at login (`lowercase_usernames`),
    | so a mixed-case entry would seed an account that can never sign in.
    |
    */

    'admins' => array_values(array_filter(array_map(fn (string $email): string => mb_strtolower(trim($email)), explode(',', (string) env('ADMIN_EMAILS', ''))))),

    'operator' => [
        'email' => mb_strtolower(trim((string) env('OPERATOR_EMAIL'))),
        'password' => env('OPERATOR_PASSWORD'),
    ],

];
