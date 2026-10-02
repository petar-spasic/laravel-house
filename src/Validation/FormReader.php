<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads a FormRequest the way Laravel would, minus the request: no route, no user, no input.
 */
final readonly class FormReader
{
    /** Hooks that change the input before validation: the browser would validate something else. */
    private const array REFUSED_HOOKS = ['prepareForValidation', 'validationData', 'validator'];

    /** Hooks that add server-side checks only. */
    private const array SERVER_HOOKS = ['withValidator', 'after', 'passedValidation'];

    public function __construct(private Container $container, private Translator $translator) {}

    /**
     * @param  class-string<FormRequest>  $class
     * @param  list<string>  $locales
     * @return array{
     *     rules: array<string, array<string, mixed>>,
     *     messages: array<string, array<string, mixed>>,
     *     attributes: array<string, array<string, mixed>>,
     *     later: array{rules: array<string, mixed>, messages: array<string, mixed>, attributes: array<string, mixed>},
     *     hooks: list<string>,
     *     failOnUnknownFields: bool,
     * } rules, messages and attributes keyed by locale; `later` is the first locale read under a later clock
     */
    public function read(string $class, array $locales): array
    {
        $reflection = new ReflectionClass($class);

        foreach (self::REFUSED_HOOKS as $hook) {
            if ($this->declaredByApp($reflection, $hook)) {
                throw new ExportRefused($class, '(form)', "{$hook}()", 'the browser would validate input Laravel never sees; move the change into the rules');
            }
        }

        /** @var FormRequest $form */
        $form = $reflection->newInstanceWithoutConstructor();
        $form->setContainer($this->container);
        $read = ['rules' => [], 'messages' => [], 'attributes' => []];

        foreach ($locales as $locale) {
            foreach ($this->call($class, $form, $locale) as $method => $value) {
                $read[$method][$locale] = $value;
            }
        }

        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now()->addDays(400)->addHours(13)->addMinutes(37)->addSeconds(11));

        try {
            $later = $this->call($class, $form, $locales[0]);
        } finally {
            Carbon::setTestNow($now);
        }

        return [
            ...$read,
            'later' => $later,
            'hooks' => array_values(array_filter(self::SERVER_HOOKS, fn (string $hook): bool => $this->declaredByApp($reflection, $hook))),
            'failOnUnknownFields' => (fn (): bool => $this->shouldFailOnUnknownFields())->call($form),
        ];
    }

    /**
     * @return array{rules: array<string, mixed>, messages: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function call(string $class, FormRequest $form, string $locale): array
    {
        $original = $this->translator->getLocale();
        $this->translator->setLocale($locale);

        try {
            return [
                'rules' => $this->container->call([$form, 'rules']),
                'messages' => $form->messages(),
                'attributes' => $form->attributes(),
            ];
        } catch (Throwable $e) {
            throw new ExportRefused($class, 'rules()', class_basename($e), $e->getMessage().' (rules(), messages() and attributes() run with no request, user or tenant)');
        } finally {
            $this->translator->setLocale($original);
        }
    }

    private function declaredByApp(ReflectionClass $class, string $method): bool
    {
        return $class->hasMethod($method)
            && ! str_starts_with((new ReflectionMethod($class->name, $method))->getDeclaringClass()->name, 'Illuminate\\');
    }
}
