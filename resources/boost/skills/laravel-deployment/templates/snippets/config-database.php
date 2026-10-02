# if:tenancy
<?php

// Merge into config/database.php, beside 'pgsql': the same keys and DB_* variables, the owner's credentials, and no
// `url`, so phpunit's forced DB_DATABASE reaches it too (CLAUDE.md, Hosting).

return [
    'connections' => [
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => '{{app}}',
            'password' => env('DB_OWNER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
];
# endif
