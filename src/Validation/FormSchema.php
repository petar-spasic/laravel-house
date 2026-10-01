<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

/**
 * Everything the TypeScript module of one form needs, before messages are rendered.
 */
final class FormSchema
{
    public Node $root;

    /** @var list<array{path: list<string>, test: string, key: string}> object-level checks */
    public array $checks = [];

    /** @var array<string, array{attribute: string, rule: string|object, params: array<array-key, mixed>, password?: bool, data?: array<string, mixed>}> message key => what to render */
    public array $messages = [];

    /** @var array<string, list<string>> field => renderer rule names, so Laravel picks the string, numeric or array message */
    public array $rendererRules = [];

    /** @var list<string> comment lines: rules Laravel checks that the browser does not */
    public array $serverOnly = [];

    /** @var list<string> comment lines: fields left out of the schema */
    public array $omitted = [];

    /** @var list<ExportRefused> */
    public array $refusals = [];

    public function __construct(public readonly string $class)
    {
        $this->root = new Node('');
    }

    public function dataType(): string
    {
        $json = false;
        $walk = function (Node $node, bool $nested) use (&$walk, &$json): void {
            if ($node->omitted) {
                return;
            }

            // superforms reads neither a nested object nor an array of arrays from FormData.
            $json = $json || ($nested && $node->type === 'object') || ($node->element !== null && $node->element->type === 'array');

            foreach ($node->children as $child) {
                $walk($child, true);
            }

            if ($node->element !== null) {
                $walk($node->element, true);
            }
        };
        $walk($this->root, false);

        return $json ? 'json' : 'form';
    }

    public function find(string $path): ?Node
    {
        $node = $this->root;

        foreach (explode('.', $path) as $segment) {
            $node = $segment === '*' ? $node->element : ($node->children[$segment] ?? null);

            if ($node === null) {
                return null;
            }
        }

        return $node;
    }
}
