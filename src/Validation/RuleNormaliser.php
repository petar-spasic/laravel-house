<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use BackedEnum;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\ArrayKeys;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Contains;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\DoesntContain;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\ProhibitedUnless;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\RequiredUnless;
use Illuminate\Validation\Rules\StringRule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;
use ReflectionProperty;

/**
 * Turns one field's rules into ParsedRules without ever running a closure.
 */
final readonly class RuleNormaliser
{
    /** Rule objects that Laravel stringifies before validating. */
    private const array STRINGIFIED = [
        In::class, NotIn::class, Date::class, Numeric::class, StringRule::class, ArrayRule::class,
        Contains::class, DoesntContain::class, Dimensions::class, ArrayKeys::class,
    ];

    /** Condition objects: [rule when the condition is true, rule when false]. */
    private const array CONDITIONS = [
        RequiredIf::class => ['required', ''],
        RequiredUnless::class => ['', 'required'],
        ExcludeIf::class => ['exclude', ''],
        ExcludeUnless::class => ['', 'exclude'],
        ProhibitedIf::class => ['prohibited', ''],
        ProhibitedUnless::class => ['', 'prohibited'],
    ];

    /**
     * @param  array<string, mixed>  $extensions  the validator factory's registered extensions
     */
    public function __construct(private array $extensions) {}

    /**
     * @return list<ParsedRule>
     */
    public function normalise(string $form, string $field, mixed $rules): array
    {
        $parsed = [];

        foreach (is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : [$rules]) as $rule) {
            array_push($parsed, ...$this->one($form, $field, $rule));
        }

        return $parsed;
    }

    /**
     * @return list<ParsedRule>
     */
    private function one(string $form, string $field, mixed $rule): array
    {
        return match (true) {
            is_string($rule) => $this->parse($form, $field, $rule, $rule),
            is_array($rule) => $this->parse($form, $field, $rule, implode(':', array_map(strval(...), $rule))),
            $rule instanceof Closure => [ParsedRule::serverOnly('closure')],
            $rule instanceof Enum => [$this->enum($form, $field, $rule)],
            $rule instanceof Password => $this->password($rule),
            $rule instanceof File => [new ParsedRule('file', [], 'File')],
            $rule instanceof ConditionalRules => $this->conditional($form, $field, $rule),
            is_object($rule) && isset(self::CONDITIONS[$rule::class]) => $this->condition($form, $field, $rule),
            $rule instanceof Unique, $rule instanceof Exists => [ParsedRule::serverOnly((string) strtok((string) $rule, ','))],
            is_object($rule) && in_array($rule::class, self::STRINGIFIED, true) => $this->normalise($form, $field, (string) $rule),
            is_object($rule) => [ParsedRule::serverOnly(class_basename($rule))],
            default => throw new ExportRefused($form, $field, get_debug_type($rule), 'not a validation rule'),
        };
    }

    /**
     * @param  string|array<int, mixed>  $rule
     * @return list<ParsedRule>
     */
    private function parse(string $form, string $field, string|array $rule, string $source): array
    {
        [$studly, $params] = ValidationRuleParser::parse($rule);

        if ($studly === '') {
            return [];
        }

        $name = Str::snake($studly);

        if (method_exists(Validator::class, 'validate'.$studly)) {
            return [new ParsedRule($name, $params, trim($source))];
        }

        if (isset($this->extensions[$name])) {
            return [ParsedRule::serverOnly(trim($source))];
        }

        throw new ExportRefused($form, $field, trim($source), 'unknown rule');
    }

    private function enum(string $form, string $field, Enum $rule): ParsedRule
    {
        $type = $this->property($rule, 'type');
        $only = $this->property($rule, 'only');
        $except = $this->property($rule, 'except');

        if (! is_string($type) || ! is_subclass_of($type, BackedEnum::class)) {
            throw new ExportRefused($form, $field, 'Rule::enum', 'only a backed enum can be exported');
        }

        $values = [];

        foreach ($type::cases() as $case) {
            $wanted = match (true) {
                $only !== [] => in_array($case, $only, true),
                $except !== [] => ! in_array($case, $except, true),
                default => true,
            };

            if ($wanted) {
                $values[] = $case->value;
            }
        }

        return new ParsedRule('enum', $values, 'Rule::enum('.class_basename($type).')', $rule);
    }

    /**
     * @return list<ParsedRule>
     */
    private function password(Password $rule): array
    {
        $flags = ['min', 'max', 'letters', 'mixedCase', 'numbers', 'symbols'];
        $config = array_combine($flags, array_map(fn (string $p): mixed => $this->property($rule, $p), $flags));

        $parsed = [
            ...($this->property($rule, 'required') ? [new ParsedRule('required', [], 'Password::required')] : []),
            ...($this->property($rule, 'sometimes') ? [new ParsedRule('sometimes', [], 'Password::sometimes')] : []),
            new ParsedRule('password', $config, 'Password', $rule),
        ];

        if ($this->property($rule, 'uncompromised')) {
            $parsed[] = ParsedRule::serverOnly('Password uncompromised');
        }

        foreach ($this->property($rule, 'customRules') as $custom) {
            $parsed[] = ParsedRule::serverOnly('Password rule '.(is_string($custom) ? $custom : class_basename($custom)));
        }

        return $parsed;
    }

    /**
     * @return list<ParsedRule>
     */
    private function condition(string $form, string $field, object $rule): array
    {
        $condition = $this->property($rule, 'condition');
        [$whenTrue, $whenFalse] = self::CONDITIONS[$rule::class];
        $source = 'Rule::'.lcfirst(class_basename($rule)).'(closure)';

        if (is_callable($condition)) {
            if ($rule instanceof ExcludeIf || $rule instanceof ExcludeUnless) {
                throw new ExportRefused($form, $field, $source, 'Laravel then skips the other rules, so the browser would be stricter');
            }

            return [ParsedRule::serverOnly($source)];
        }

        return $this->normalise($form, $field, $condition ? $whenTrue : $whenFalse);
    }

    /**
     * @return list<ParsedRule>
     */
    private function conditional(string $form, string $field, ConditionalRules $rule): array
    {
        $condition = $this->property($rule, 'condition');
        $rules = $this->property($rule, $condition ? 'rules' : 'defaultRules');

        if (is_callable($condition) || $rules instanceof Closure) {
            return [ParsedRule::serverOnly('Rule::when(closure)')];
        }

        return $this->normalise($form, $field, $rules);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
