<?php

<!-- if:spa -->
// Merge these keys into the array config/sanctum.php returns (install:api publishes it; root CLAUDE.md, Auth).
<!-- endif -->
<!-- unless:spa -->
// Merge this key into the array config/sanctum.php returns (install:api publishes it; root CLAUDE.md, Auth).
<!-- endif -->

return [

<!-- if:spa -->
    // The SvelteKit app authenticates by the session cookie.
    'guard' => ['web'],

    // The origins SANCTUM_STATEFUL_DOMAINS lists (Hosting), plus APP_URL's host[:port] in every process: one that
    // `docker compose exec` starts (the tests) has only compose's list.
    'stateful' => array_values(array_unique(array_filter([
        ...array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))),
        rtrim(parse_url((string) config('app.url'), PHP_URL_HOST).':'.parse_url((string) config('app.url'), PHP_URL_PORT), ':'),
    ]))),
<!-- endif -->
<!-- unless:spa -->
    // Token-only: auth:sanctum never falls back to the web session.
    'guard' => [],
<!-- endif -->

];
