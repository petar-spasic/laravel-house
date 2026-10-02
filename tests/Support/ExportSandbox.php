<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** A throwaway app for `php artisan validation:export`: its own app/ and frontend/, the paths rebound here. */
final class ExportSandbox
{
    private readonly string $namespace;

    public function __construct(public readonly string $root)
    {
        // Each sandbox declares its forms in its own namespace: one test process loads many.
        $this->namespace = 'Acme\\Forms'.bin2hex(random_bytes(4));
        mkdir($root.'/app/Http/Requests', 0775, true);
        mkdir($root.'/frontend', 0775, true);
        app()->setBasePath($root);
        app()->useAppPath($root.'/app');
    }

    public static function create(): self
    {
        return new self(Sandbox::tmp());
    }

    /** Declares a FormRequest: `$code` is the class from its attribute on, without namespace or imports. */
    public function form(string $class, string $code): self
    {
        $file = "{$this->root}/app/Http/Requests/{$class}.php";
        file_put_contents($file, implode("\n", [
            '<?php',
            "namespace {$this->namespace};",
            'use Illuminate\Foundation\Http\FormRequest;',
            'use PetarSpasic\LaravelHouse\Validation\ExportValidation;',
            $code,
        ]));
        require $file;

        return $this;
    }

    /** Writes the parity spec of an exported form. */
    public function prove(string $name): self
    {
        @mkdir("{$this->root}/frontend/e2e/parity", 0775, true);
        file_put_contents("{$this->root}/frontend/e2e/parity/{$name}.spec.ts", "// parity proof\n");

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, string} exit code and output
     */
    public function export(array $options = []): array
    {
        $code = Artisan::call('validation:export', $options);

        return [$code, Artisan::output()];
    }

    public function module(string $name): string
    {
        $file = "{$this->root}/frontend/src/lib/validation/generated/{$name}.ts";

        return is_file($file) ? (string) file_get_contents($file) : throw new RuntimeException("{$name}.ts was not written");
    }
}
