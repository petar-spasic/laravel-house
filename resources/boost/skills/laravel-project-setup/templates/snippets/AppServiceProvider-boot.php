<?php

// Merge into app/Providers/AppServiceProvider.php. Imports:
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;

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
