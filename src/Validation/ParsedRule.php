<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

/**
 * One rule of one field, after Laravel's own parsing: `name` is snake case (max, required_if, password, enum).
 */
final readonly class ParsedRule
{
    /**
     * @param  array<array-key, mixed>  $params
     */
    public function __construct(
        public string $name,
        public array $params,
        public string $source,
        public ?object $object = null,
        public bool $serverOnly = false,
    ) {}

    public static function serverOnly(string $source): self
    {
        return new self('', [], $source, serverOnly: true);
    }

    public function studly(): string
    {
        return str_replace('_', '', ucwords($this->name, '_'));
    }
}
