<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use Attribute;

/**
 * Marks a FormRequest for php artisan validation:export; the name becomes <name>.ts.
 * Only the command instantiates it, so a production build without this package is unaffected.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExportValidation
{
    public function __construct(public string $name)
    {
    }
}
