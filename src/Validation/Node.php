<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

/**
 * One field of the generated schema; `path` uses Laravel's dotted form (items.*.name).
 */
final class Node
{
    /** string, number, boolean, array or object */
    public string $type = 'object';

    /** @var list<ParsedRule>|null null when rules() has no key for this path */
    public ?array $rules = null;

    public bool $nullable = false;

    public bool $optional = false;

    public bool $trim = false;

    public bool $bail = false;

    public bool $omitted = false;

    /** @var list<array{test: string, key: string, implicit: bool, group: ?string}> */
    public array $steps = [];

    /** Message keys for a value of the wrong type: when blank, and otherwise. */
    public ?string $blankKey = null;

    public ?string $typeKey = null;

    /** @var array<string, Node> */
    public array $children = [];

    public ?Node $element = null;

    public function __construct(public readonly string $path) {}

    public function inWildcard(): bool
    {
        return str_contains($this->path, '*');
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_unique(array_map(
            fn (ParsedRule $rule): string => $rule->name,
            array_filter($this->rules ?? [], fn (ParsedRule $rule): bool => ! $rule->serverOnly),
        )));
    }
}
