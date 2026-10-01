<?php

// Merge this key into the array config/sanctum.php returns (install:api publishes it; root CLAUDE.md, Auth).

return [

<!-- if:spa -->
    // The SvelteKit app authenticates by the session cookie; SANCTUM_STATEFUL_DOMAINS (Hosting) lists its origins.
    'guard' => ['web'],
<!-- endif -->
<!-- unless:spa -->
    // Token-only: auth:sanctum never falls back to the web session.
    'guard' => [],
<!-- endif -->

];
