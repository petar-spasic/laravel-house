<?php

// Merge these keys into the array config/fortify.php returns (routes/CLAUDE.md, Fortify).

use App\Http\Middleware\AcceptJson;

return [

    // AcceptJson while 'views' is off (routes/CLAUDE.md); `auth-forms` is defined in FortifyServiceProvider.
    'middleware' => ['web', AcceptJson::class, 'throttle:auth-forms'],

    // Off until the auth views exist: on, with no Fortify::*View registered, every GET page 500s.
    'views' => false,

];
