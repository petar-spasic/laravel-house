<?php

// Merge these keys into the array config/fortify.php returns (routes/CLAUDE.md, Fortify).

use App\Http\Middleware\AcceptJson;

return [

    // AcceptJson while 'views' is off (routes/CLAUDE.md).
    'middleware' => ['web', AcceptJson::class],

    // Off until the auth views exist: on, with no Fortify::*View registered, every GET page 500s.
    'views' => false,

];
