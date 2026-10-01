<?php

// Merge these keys into the array config/fortify.php returns (routes/CLAUDE.md, Fortify).

use App\Http\Middleware\AcceptJson;

return [

<!-- if:spa -->
    // Fortify's routes are the package's: no API version.
    'prefix' => 'api/auth',

    // The SvelteKit app's home.
    'home' => '/',

<!-- endif -->
    // AcceptJson while 'views' is off (routes/CLAUDE.md); `auth-forms` is defined in FortifyServiceProvider.
    'middleware' => ['web', AcceptJson::class, 'throttle:auth-forms'],

<!-- if:htmx -->
    // Off until the auth pages exist (resources/CLAUDE.md, Auth pages): on without them, every GET page 500s.
<!-- endif -->
<!-- unless:htmx -->
    // The client renders every auth page.
<!-- endif -->
    'views' => false,

];
