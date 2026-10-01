<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Renders messages with Laravel's own Validator code, so the browser shows the text of the 422.
 */
final class MessageRenderer extends Validator
{
    /**
     * @param  array<string, list<string>>  $rules  field => rule names
     * @param  array<string, mixed>  $messages  the form's messages()
     * @param  array<string, mixed>  $attributes  the form's attributes()
     */
    public static function make(Translator $translator, array $rules, array $messages, array $attributes): self
    {
        $data = [];
        $paths = array_keys($rules);

        foreach ($paths as $path) {
            $leaf = array_filter($paths, fn (string $other): bool => str_starts_with($other, "{$path}.")) === [];

            if ($leaf) {
                foreach ([0, 1] as $index) {
                    Arr::set($data, str_replace('*', (string) $index, $path), '');
                }
            }
        }

        return new self($translator, $data, $rules, $messages, $attributes);
    }

    /**
     * Renders one message for a field at a concrete index of every `*`.
     *
     * @param  array{attribute: string, rule: string|object, params: array<array-key, mixed>, password?: bool, data?: array<string, mixed>}  $request
     */
    public function render(array $request, int $index): string
    {
        $attribute = str_replace('*', (string) $index, $request['attribute']);
        $rule = $request['rule'];
        $saved = $this->data;

        foreach ($request['data'] ?? [] as $path => $value) {
            Arr::set($this->data, $path, $value);
        }

        try {
            if (is_object($rule)) {
                return $this->ruleObject($attribute, $rule);
            }

            if (($request['password'] ?? false) && ($override = $this->getFromLocalArray($attribute, Password::class)) !== null) {
                return $this->replace((string) $override, $attribute, Password::class, []);
            }

            $message = $this->replace($this->getMessage($attribute, $rule), $attribute, $rule, $request['params']);

            return ($request['password'] ?? false) ? $this->makeReplacements($message, $attribute, Password::class, []) : $message;
        } finally {
            $this->data = $saved;
        }
    }

    public function attributeName(string $path): string
    {
        return $this->getDisplayableAttribute(str_replace('*', '0', $path));
    }

    /** Laravel's validateUsingCustomRule message path. */
    private function ruleObject(string $attribute, object $rule): string
    {
        if ($rule instanceof ValidatorAwareRule) {
            $rule->setValidator($this);
        }

        $messages = $this->getFromLocalArray($attribute, $rule::class) ?? (method_exists($rule, 'message') ? $rule->message() : null);
        $messages = $messages ? (array) $messages : [$rule::class];

        if (count($messages) !== 1) {
            throw new InvalidArgumentException('the rule reports several messages at once');
        }

        return $this->replace((string) reset($messages), $attribute, $rule::class, []);
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function replace(string $message, string $attribute, string $rule, array $params): string
    {
        if (preg_match('/:(input|index|position|ordinal-position)\b/i', $message, $m) === 1) {
            throw new InvalidArgumentException("the message uses :{$m[1]}, which depends on the submitted value or its position");
        }

        return $this->makeReplacements($message, $attribute, $rule, $params);
    }
}
