<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use InvalidArgumentException;

/**
 * Maps each field's ParsedRules onto the schema tree. The browser may be laxer than Laravel, never stricter:
 * a rule that cannot be mirrored exactly is left to the server and listed.
 */
final class FieldTranslator
{
    /** Rules that settle an empty string or number, which Laravel turns into null. */
    private const array SETTLING = ['required', 'nullable', 'filled', 'accepted', 'declined'];

    private const array IMPLICIT = ['required' => '!blank(v)', 'filled' => '!blank(v)', 'accepted' => 'v === true', 'declined' => 'v === false'];

    private const array NO_CLIENT_EFFECT = ['nullable', 'sometimes', 'present', 'bail', 'string', 'boolean', 'list'];

    private const array UPLOAD = ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    private const array OMIT = ['prohibited', 'exclude', 'missing'];

    private const array REFUSED = ['exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without'];

    private const array NUMERIC = ['numeric', 'integer', 'decimal'];

    private const array TYPE_RULES = ['string', 'integer', 'numeric', 'boolean', 'array', 'list'];

    private const array CROSS = [
        'confirmed' => 'same', 'same' => 'same', 'different' => 'different',
        'required_if' => 'requiredIf', 'required_unless' => 'requiredUnless',
        'required_with' => 'requiredWith', 'required_with_all' => 'requiredWithAll',
        'required_without' => 'requiredWithout', 'required_without_all' => 'requiredWithoutAll',
    ];

    private const array DATE_ORDER = ['before' => '<', 'after' => '>', 'before_or_equal' => '<=', 'after_or_equal' => '>=', 'date_equals' => '==='];

    private const array DATE_FORMATS = [
        'Y-m-d' => 'isIsoDate(v)',
        'Y-m-d\TH:i' => '/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/u.test(v) && isIsoDate(v.slice(0, 10))',
        'H:i' => '/^([01]\d|2[0-3]):[0-5]\d$/u.test(v)',
    ];

    private const array PASSWORD = [
        'mixedCase' => ['mixed', '/(\p{Ll}+[^\n]*\p{Lu})|(\p{Lu}+[^\n]*\p{Ll})/u.test(v)'],
        'letters' => ['letters', '/\p{L}/u.test(v)'],
        'symbols' => ['symbols', '/\p{Z}|\p{S}|\p{P}/u.test(v)'],
        'numbers' => ['numbers', '/\p{N}/u.test(v)'],
    ];

    private FormSchema $schema;

    private bool $failOnUnknownFields;

    /** @var array<string, list<string>> path => tests of its earlier required_* cross checks */
    private array $implicitCross = [];

    /** @var list<Node> declared nodes in rules() order */
    private array $declared = [];

    public function __construct(
        private readonly RequestPipeline $pipeline,
        private readonly RegexTranslator $regex = new RegexTranslator,
    ) {}

    /**
     * @param  class-string  $class
     * @param  array<string, list<ParsedRule>>  $rules
     */
    public function translate(string $class, array $rules, bool $failOnUnknownFields): FormSchema
    {
        $this->schema = new FormSchema($class);
        $this->failOnUnknownFields = $failOnUnknownFields;
        $this->declared = [];
        $this->implicitCross = [];

        foreach ($rules as $path => $parsed) {
            try {
                $node = $this->insert((string) $path);
                $node->rules = $parsed;
                $this->declared[] = $node;
            } catch (ExportRefused $e) {
                $this->schema->refusals[] = $e;
            }
        }

        foreach ($this->declared as $node) {
            $this->omit($node);
        }

        foreach ($this->declared as $node) {
            if ($node->omitted) {
                continue;
            }

            try {
                $this->field($node);
            } catch (ExportRefused $e) {
                $this->schema->refusals[] = $e;
            }
        }

        $this->settleContainers($this->schema->root);

        return $this->schema;
    }

    private function insert(string $path): Node
    {
        $node = $this->schema->root;
        $prefix = [];

        foreach (explode('.', $path) as $segment) {
            $prefix[] = $segment;
            $at = implode('.', $prefix);

            if ($segment === '*') {
                $node = $node->element ??= new Node($at);
            } else {
                $node = $node->children[$segment] ??= new Node($at);
            }
        }

        return $node;
    }

    private function settleContainers(Node $node): void
    {
        if ($node->element !== null && $node->children !== []) {
            $this->schema->refusals[] = $this->refusal($node, '', 'both *-elements and named keys; split the field');
        }

        if ($node->rules === null && $node->element !== null) {
            $node->type = 'array';
        }

        foreach ([...$node->children, ...($node->element ? [$node->element] : [])] as $child) {
            $this->settleContainers($child);
        }
    }

    private function omit(Node $node): void
    {
        $names = $node->names();

        foreach ($node->rules ?? [] as $rule) {
            if (in_array($rule->name, self::REFUSED, true)) {
                $this->schema->refusals[] = $this->refusal($node, $rule->source, 'Laravel then skips the field\'s other rules, so the browser would be stricter');
                $node->omitted = true;

                return;
            }
        }

        if (array_intersect($names, self::UPLOAD) !== []) {
            $this->schema->omitted[] = "{$node->path}: handled by the upload path";
            $node->omitted = true;
        } elseif (($omit = array_intersect($names, self::OMIT)) !== []) {
            $this->schema->omitted[] = "{$node->path}: ".implode(', ', $omit);
            $node->omitted = true;
        }
    }

    private function field(Node $node): void
    {
        $names = $node->names();
        $node->type = $this->type($node, $names);
        // superforms cannot parse a nullable array from FormData; an empty one posts [] instead of null.
        $node->nullable = (in_array('nullable', $names, true) && $node->type !== 'array') || $node->type === 'number';
        $node->optional = in_array('sometimes', $names, true);
        $node->bail = in_array('bail', $names, true);
        $node->trim = $node->type === 'string' && $this->pipeline->trims($node->path);
        $this->schema->rendererRules[$node->path] = $names;

        if (in_array($node->type, ['string', 'number'], true) && array_intersect($names, self::SETTLING) === []) {
            throw $this->refusal($node, $this->sources($node), 'add required or nullable: Laravel turns an empty value into null, which then fails the type rule');
        }

        if ($this->pipeline->emptyToNull && in_array($node->type, ['string', 'number'], true) && ! in_array('nullable', $names, true)) {
            $this->presenceFirst($node);
        }

        foreach ($node->rules ?? [] as $rule) {
            if ($rule->serverOnly) {
                $this->serverOnly($node, $rule);

                continue;
            }

            $this->rule($node, $rule);

            if (in_array($rule->name, self::TYPE_RULES, true)) {
                $node->typeKey ??= $this->message($node, $rule);
            }
        }

        foreach ($node->steps as $step) {
            if ($step['implicit']) {
                $node->blankKey = $step['key'];

                break;
            }
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function type(Node $node, array $names): string
    {
        $types = array_values(array_unique(array_filter([
            array_intersect($names, self::NUMERIC) !== [] ? 'number' : null,
            in_array('boolean', $names, true) ? 'boolean' : null,
            in_array('string', $names, true) ? 'string' : null,
            $node->children !== [] ? 'object' : null,
            $node->element !== null || (array_intersect($names, ['array', 'list']) !== [] && $node->children === []) ? 'array' : null,
        ])));

        if (count($types) > 1) {
            throw $this->refusal($node, $this->sources($node), 'conflicting type rules ('.implode(', ', $types).')');
        }

        if ($types !== []) {
            return $types[0];
        }

        if (array_intersect($names, ['accepted', 'declined']) !== []) {
            return 'boolean';
        }

        foreach ($node->rules ?? [] as $rule) {
            if ($rule->name === 'enum' && $rule->params !== [] && array_filter($rule->params, is_int(...)) === $rule->params) {
                return 'number';
            }
        }

        $valueRules = array_filter($names, fn (string $name): bool => ! in_array($name, ['nullable', 'sometimes', 'filled', 'bail'], true)
            && ! str_starts_with($name, 'required') && ! str_starts_with($name, 'present'));

        if ($valueRules === []) {
            throw $this->refusal($node, $this->sources($node), 'add a type rule (e.g. string or boolean): the schema drops input it does not declare');
        }

        return 'string';
    }

    /** On a '' turned into null, Laravel runs every rule before `required` and stops only there. */
    private function presenceFirst(Node $node): void
    {
        $names = array_map(fn (ParsedRule $rule): string => $rule->name, $node->rules ?? []);
        $first = array_key_first(array_intersect($names, ['required', 'filled']));

        if ($first !== null && array_diff(array_slice($names, 0, $first), ['sometimes', 'bail', 'present']) !== []) {
            throw $this->refusal($node, $this->sources($node), 'put required first: Laravel runs the rules before it on an empty value');
        }
    }

    private function rule(Node $node, ParsedRule $rule): void
    {
        $name = $rule->name;
        $p = $rule->params;
        $type = $node->type;

        if (isset(self::IMPLICIT[$name])) {
            if (in_array($name, ['accepted', 'declined'], true) && $type !== 'boolean') {
                $this->serverOnly($node, $rule);
            } else {
                $this->step($node, self::IMPLICIT[$name], $rule, implicit: true);
            }

            return;
        }

        if (in_array($name, self::NO_CLIENT_EFFECT, true) || ($name === 'array' && $p === [])) {
            return;
        }

        if (isset(self::CROSS[$name])) {
            $this->cross($node, $rule);

            return;
        }

        if ($name === 'password') {
            $this->password($node, $rule);

            return;
        }

        if (isset(self::DATE_ORDER[$name]) && count($p) === 1 && ! $this->isIsoDate((string) $p[0]) && $this->schema->find((string) $p[0]) !== null) {
            $this->crossDate($node, $rule);

            return;
        }

        try {
            $test = match ($type) {
                'number' => $this->numberTest($name, $p),
                'string' => $this->stringTest($node, $name, $p),
                'array' => in_array($name, ['min', 'max', 'between', 'size'], true) ? $this->size('v.length', $name, $p) : null,
                default => null,
            };
        } catch (InvalidArgumentException $e) {
            throw $this->refusal($node, $rule->source, $e->getMessage());
        }

        if ($test === null) {
            $this->serverOnly($node, $rule);

            return;
        }

        $this->step($node, $test, $rule);

        if ($name === 'email' && array_intersect($p, ['dns', 'spoof']) !== []) {
            $this->serverOnly($node, $rule, 'email:'.implode(',', array_intersect($p, ['dns', 'spoof'])));
        } elseif ($name === 'uuid' && $p !== []) {
            $this->serverOnly($node, $rule, 'uuid version '.$p[0]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $p
     */
    private function numberTest(string $name, array $p): ?string
    {
        return match ($name) {
            'integer' => 'Number.isInteger(v)',
            'numeric' => 'Number.isFinite(v)',
            'min', 'max', 'between', 'size' => $this->size('v', $name, $p),
            'in' => $this->js(array_map(strval(...), $p)).'.includes(String(v))',
            'not_in' => '!'.$this->js(array_map(strval(...), $p)).'.includes(String(v))',
            'enum' => array_filter($p, is_int(...)) === $p ? $this->js($p).'.includes(v)' : null,
            'digits', 'digits_between' => $this->digits('String(v)', $p),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $p
     */
    private function stringTest(Node $node, string $name, array $p): ?string
    {
        $ascii = ($p[0] ?? null) === 'ascii';

        return match ($name) {
            'min', 'max', 'between', 'size' => $this->size('chars(v)', $name, $p),
            'email' => array_diff($p, ['rfc', 'strict', 'filter', 'filter_unicode', 'dns', 'spoof']) === [] ? '/^.+@.+$/su.test(v)' : null,
            'url' => '/^[a-z][a-z0-9+.-]*:\/\/.+$/isu.test(v)'.($p === [] ? '' : ' && '.$this->js(array_map(fn ($s): string => strtolower((string) $s), $p)).'.includes(v.slice(0, v.indexOf(\':\')).toLowerCase())'),
            'uuid' => 'z.regexes.guid.test(v)',
            'date' => '!/^\d{4}-\d{2}-\d{2}$/u.test(v) || isIsoDate(v)',
            'date_format' => array_diff($p, array_keys(self::DATE_FORMATS)) === []
                ? implode(' || ', array_map(fn ($f): string => '('.self::DATE_FORMATS[$f].')', $p))
                : null,
            'before', 'after', 'before_or_equal', 'after_or_equal', 'date_equals' => count($p) === 1 && $this->isIsoDate((string) $p[0])
                ? '!isIsoDate(v) || v '.self::DATE_ORDER[$name].' '.$this->js($p[0])
                : null,
            'in' => $this->js(array_map(strval(...), $p)).'.includes(v)',
            'not_in' => '!'.$this->js(array_map(strval(...), $p)).'.includes(v)',
            'enum' => array_filter($p, is_string(...)) === $p ? $this->js($p).'.includes(v)' : null,
            'regex', 'not_regex' => ($name === 'not_regex' ? '!' : '').$this->regex($node, $name, (string) $p[0]).'.test(v)',
            'digits', 'digits_between' => $this->digits('v', $p),
            'alpha' => $ascii ? '/^[a-zA-Z]+$/u.test(v)' : '/^[\p{L}\p{M}]+$/u.test(v)',
            'alpha_num' => $ascii ? '/^[a-zA-Z0-9]+$/u.test(v)' : '/^[\p{L}\p{M}\p{N}]+$/u.test(v)',
            'alpha_dash' => $ascii ? '/^[a-zA-Z0-9_-]+$/u.test(v)' : '/^[\p{L}\p{M}\p{N}_-]+$/u.test(v)',
            'lowercase' => 'v.toLowerCase() === v',
            'uppercase' => 'v.toUpperCase() === v',
            'starts_with' => $this->affix('startsWith', $p),
            'ends_with' => $this->affix('endsWith', $p),
            'doesnt_start_with' => '!('.$this->affix('startsWith', $p).')',
            'doesnt_end_with' => '!('.$this->affix('endsWith', $p).')',
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $p
     */
    private function size(string $size, string $name, array $p): string
    {
        $n = array_map($this->number(...), $p);

        return match ($name) {
            'min' => "{$size} >= {$n[0]}",
            'max' => "{$size} <= {$n[0]}",
            'size' => "{$size} === {$n[0]}",
            'between' => "{$size} >= {$n[0]} && {$size} <= {$n[1]}",
        };
    }

    /**
     * @param  array<array-key, mixed>  $p
     */
    private function digits(string $value, array $p): string
    {
        $n = array_map($this->number(...), $p);
        $length = "{$value}.length";

        return "/^[0-9]*$/u.test({$value}) && ".(count($n) === 1 ? "{$length} === {$n[0]}" : "{$length} >= {$n[0]} && {$length} <= {$n[1]}");
    }

    /**
     * @param  array<array-key, mixed>  $p
     */
    private function affix(string $method, array $p): string
    {
        $needles = array_values(array_filter(array_map(strval(...), $p), fn (string $s): bool => $s !== ''));

        return $needles === [] ? 'false' : $this->js($needles).".some((p) => v.{$method}(p))";
    }

    private function regex(Node $node, string $name, string $pattern): string
    {
        try {
            return $this->regex->translate($pattern, $node->trim);
        } catch (InvalidArgumentException $e) {
            throw $this->refusal($node, "{$name}:{$pattern}", $e->getMessage());
        }
    }

    private function password(Node $node, ParsedRule $rule): void
    {
        if ($node->type !== 'string') {
            $this->serverOnly($node, $rule);

            return;
        }

        $config = $rule->params;
        $node->typeKey ??= $this->passwordMessage($node, $rule, 'String', 'string');
        $this->step($node, "chars(v) >= {$config['min']}", $rule, key: $this->passwordMessage($node, $rule, 'Min', 'min', [$config['min']]), group: 'password');

        if ($config['max']) {
            $this->step($node, "chars(v) <= {$config['max']}", $rule, key: $this->passwordMessage($node, $rule, 'Max', 'max', [$config['max']]), group: 'password');
        }

        foreach (self::PASSWORD as $flag => [$suffix, $test]) {
            if ($config[$flag]) {
                $this->step($node, $test, $rule, key: $this->passwordMessage($node, $rule, "password.{$suffix}", $suffix), group: 'password');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function passwordMessage(Node $node, ParsedRule $rule, string $laravelRule, string $suffix, array $params = []): string
    {
        $key = "{$node->path}.password.{$suffix}";
        $this->schema->messages[$key] = ['attribute' => $node->path, 'rule' => $laravelRule, 'params' => $params, 'password' => true];

        return $key;
    }

    private function cross(Node $node, ParsedRule $rule): void
    {
        $p = $rule->params;
        $single = in_array($rule->name, ['required_if', 'required_unless'], true);

        if ($node->inWildcard() || $node->bail || ($single && count($p) !== 2)) {
            $this->serverOnly($node, $rule);

            return;
        }

        $others = $single ? [$p[0]] : ($rule->name === 'confirmed' ? [$p[0] ?? "{$node->path}_confirmation"] : $p);

        foreach ($others as $other) {
            if (str_contains((string) $other, '*') || $this->schema->find((string) $other)?->omitted) {
                $this->serverOnly($node, $rule);

                return;
            }
        }

        if ($rule->name === 'confirmed' && $this->schema->find($others[0]) === null) {
            if ($this->failOnUnknownFields) {
                throw $this->refusal($node, $rule->source, "declare {$others[0]} in rules()");
            }

            $confirmation = $this->insert($others[0]);
            $confirmation->type = 'string';
        }

        $function = self::CROSS[$rule->name];
        $a = $this->value($node->path);
        $values = array_map($this->value(...), $others);
        $data = [];

        $test = match (true) {
            $single => "{$function}({$a}, {$values[0]}, ".$this->js((string) $p[1]).')',
            $function === 'same' => "same({$a}, {$values[0]})",
            default => "{$function}({$a}, [".implode(', ', $values).'])',
        };

        $guards = [];

        if (str_starts_with($rule->name, 'required')) {
            // Laravel stops the field at its first failing required_* rule.
            $guards = array_map(fn (string $earlier): string => "!{$earlier}", $this->implicitCross[$node->path] ?? []);
            $this->implicitCross[$node->path][] = $test;
        }

        if ($node->optional) {
            // sometimes: Laravel skips a field whose key is absent.
            array_unshift($guards, $this->access($node->path).' === undefined');
        }

        $test = implode(' || ', [...$guards, $test]);

        if ($rule->name === 'required_if') {
            $other = $this->schema->find($p[0]);
            $value = (string) $p[1];
            $data[$p[0]] = match (true) {
                $other?->type === 'boolean' && in_array($value, ['true', 'false'], true) => $value === 'true',
                strtolower($value) === 'null' => null,
                default => $value,
            };
        }

        $this->schema->checks[] = [
            'path' => explode('.', $node->path),
            'test' => $test,
            'key' => $this->message($node, $rule, $data),
        ];
    }

    private function crossDate(Node $node, ParsedRule $rule): void
    {
        $other = (string) $rule->params[0];

        if ($node->inWildcard() || $node->bail || str_contains($other, '*') || $this->schema->find($other)?->omitted) {
            $this->serverOnly($node, $rule);

            return;
        }

        $this->schema->checks[] = [
            'path' => explode('.', $node->path),
            'test' => "dateOrder({$this->value($node->path)}, {$this->value($other)}, ".$this->js(self::DATE_ORDER[$rule->name]).')',
            'key' => $this->message($node, $rule),
        ];
    }

    /** The value Laravel validates for a non-wildcard path, as a JS expression over the parsed object `d`. */
    private function value(string $path): string
    {
        if ($this->schema->find($path) === null) {
            return 'undefined';
        }

        return 'prep('.$this->access($path).', '.($this->pipeline->trims($path) ? 'true' : 'false').')';
    }

    /** The raw value at a non-wildcard path of the parsed object `d`. */
    private function access(string $path): string
    {
        $access = 'd';

        foreach (explode('.', $path) as $i => $segment) {
            $safe = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $segment) === 1;
            $access .= ($i > 0 ? '?.' : ($safe ? '.' : '')).($safe ? $segment : '['.$this->js($segment).']');
        }

        return $access;
    }

    private function step(Node $node, string $test, ParsedRule $rule, bool $implicit = false, ?string $key = null, ?string $group = null): void
    {
        $node->steps[] = ['test' => $test, 'key' => $key ?? $this->message($node, $rule), 'implicit' => $implicit, 'group' => $group];
    }

    /**
     * @param  array<string, mixed>  $data  sample values Laravel's message needs (required_if's :value)
     */
    private function message(Node $node, ParsedRule $rule, array $data = []): string
    {
        $request = [
            'attribute' => $node->path,
            'rule' => $rule->object ?? $rule->studly(),
            'params' => $rule->params,
            ...($data === [] ? [] : ['data' => $data]),
        ];
        $key = "{$node->path}.{$rule->name}";

        // The same rule twice with other parameters (two required_if) gets its own message: .2, .3, …
        for ($n = 2; isset($this->schema->messages[$key]) && $this->schema->messages[$key] !== $request; $n++) {
            $key = "{$node->path}.{$rule->name}.{$n}";
        }

        $this->schema->messages[$key] = $request;

        return $key;
    }

    private function serverOnly(Node $node, ParsedRule $rule, ?string $source = null): void
    {
        $this->schema->serverOnly[] = "{$node->path}: ".($source ?? $rule->source);
    }

    private function refusal(Node $node, string $rule, string $reason): ExportRefused
    {
        return new ExportRefused($this->schema->class, $node->path, $rule === '' ? '(field)' : $rule, $reason);
    }

    private function sources(Node $node): string
    {
        return implode('|', array_map(fn (ParsedRule $rule): string => $rule->source, $node->rules ?? []));
    }

    private function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("'{$value}' is not a number");
        }

        $n = 0 + $value;

        return is_int($n) ? (string) $n : json_encode($n, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function isIsoDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function js(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
