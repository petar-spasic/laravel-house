<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * How Laravel prepares input before a FormRequest validates it.
 */
final readonly class RequestPipeline
{
    /**
     * @param  list<string>  $except  TrimStrings patterns that are never trimmed
     */
    public function __construct(
        public bool $trims,
        public bool $emptyToNull,
        public array $except,
        public bool $skipCallbacks,
    ) {
    }

    public static function read(Application $app): self
    {
        // bootstrap/app.php's withMiddleware() callback runs only once the HTTP kernel is resolved.
        $kernel = $app->make(Kernel::class);
        $global = method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [];

        $trim = null;
        $emptyToNull = false;

        foreach ($global as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $trim ??= is_a($middleware, TrimStrings::class, true) ? $middleware : null;
            $emptyToNull = $emptyToNull || is_a($middleware, ConvertEmptyStringsToNull::class, true);
        }

        $static = fn (string $name): array => (new ReflectionProperty(TrimStrings::class, $name))->getValue();

        return new self(
            trims: $trim !== null,
            emptyToNull: $emptyToNull,
            except: $trim === null ? [] : array_values(array_unique([
                ...(new ReflectionProperty($trim, 'except'))->getValue(new $trim),
                ...$static('neverTrim'),
            ])),
            skipCallbacks: $static('skipCallbacks') !== [],
        );
    }

    public function trims(string $path): bool
    {
        return $this->trims && ! Str::is($this->except, str_replace('*', '0', $path));
    }
}
