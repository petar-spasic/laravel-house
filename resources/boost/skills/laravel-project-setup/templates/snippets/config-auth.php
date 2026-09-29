<?php

// Merge these keys into the array config/auth.php returns.

return [

    /*
    |--------------------------------------------------------------------------
    | Admins and the production operator
    |--------------------------------------------------------------------------
    |
    | Admins are an email allow-list until roles exist (the viewHorizon gate);
    | what the seeders do with them and the operator is in database/CLAUDE.md.
    | Both addresses are lower-cased: Fortify lower-cases the email at login
    | (`lowercase_usernames`), so a mixed-case entry would seed an account that
    | can never sign in.
    |
    */

    'admins' => array_values(array_unique(array_filter(array_map(fn (string $email): string => mb_strtolower(trim($email)), explode(',', (string) env('ADMIN_EMAILS', '')))))),

    'operator' => [
        'email' => mb_strtolower(trim((string) env('OPERATOR_EMAIL'))),
        'password' => env('OPERATOR_PASSWORD'),
    ],

];
