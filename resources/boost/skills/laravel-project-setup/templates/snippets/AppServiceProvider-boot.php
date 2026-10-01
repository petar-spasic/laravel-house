<?php

// Merge into app/Providers/AppServiceProvider.php. Imports:
<!-- if:htmx -->
use Illuminate\Cache\RateLimiting\Limit;
<!-- endif -->
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
<!-- if:htmx -->
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
<!-- endif -->

// boot():

        Model::shouldBeStrict(! $this->app->isProduction());

        // The primary key is added as a command, not as a fluent modifier: fluent indexes
        // compile after the foreign keys queued by constrained(), which breaks self-references.
        Blueprint::macro('prefixedId', function (): ColumnDefinition {
            /** @var Blueprint $this */
            $column = $this->string('id', 20);
            $this->primary('id');

            return $column;
        });

        Blueprint::macro('foreignPrefixedId', function (string $column): ForeignIdColumnDefinition {
            /** @var Blueprint $this */
            return $this->addColumnDefinition(new ForeignIdColumnDefinition($this, [
                'type' => 'string',
                'name' => $column,
                'length' => 20,
            ]));
        });
<!-- if:htmx -->

        // The `public` group's limiter. Defined here, not in bootstrap/app.php: `withRouting(then:)` does not run when
        // routes are cached, and a missing named limiter is a 500 on every throttled route.
        // The ceiling is `public_per_minute` in config/{{app}}.php.
        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute((int) config('{{app}}.public_per_minute', 120))->by($request->ip()));
<!-- endif -->
