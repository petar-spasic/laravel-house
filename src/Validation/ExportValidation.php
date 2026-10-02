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
    /**
     * @param  'json'|null  $dataType  posts the form as JSON even when its fields would travel as FormData
     * @param  list<string>  $maps  array fields keyed by strings, exported as records instead of lists
     */
    public function __construct(
        public string $name,
        public ?string $dataType = null,
        public array $maps = [],
    ) {}
}
