<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class FormDiscovery
{
    private const string ATTRIBUTE = '#[ExportValidation]';

    /** @var list<ExportRefused> */
    public array $refusals = [];

    /**
     * @return array<string, class-string<FormRequest>> name => class, sorted by name
     */
    public function discover(string $directory): array
    {
        $forms = [];

        if (! is_dir($directory)) {
            return $forms;
        }

        $paths = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            $code = (string) file_get_contents($path);

            if (! str_contains($code, 'ExportValidation')) {
                continue;
            }

            foreach ($this->classesIn($code) as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                $name = $this->nameOf(new ReflectionClass($class));

                if ($name === null) {
                    continue;
                }

                if (isset($forms[$name])) {
                    $this->refuse($class, "the name '{$name}' is also used by {$forms[$name]}");

                    continue;
                }

                $forms[$name] = $class;
            }
        }

        ksort($forms, SORT_STRING);

        return $forms;
    }

    private function nameOf(ReflectionClass $class): ?string
    {
        $attributes = $class->getAttributes(ExportValidation::class);

        if ($attributes === []) {
            return null;
        }

        if (count($attributes) > 1) {
            return $this->refuse($class->name, 'the attribute is repeated');
        }

        if (! $class->isSubclassOf(FormRequest::class) || $class->isAbstract()) {
            return $this->refuse($class->name, 'only a concrete FormRequest can be exported');
        }

        $export = $attributes[0]->newInstance();
        $name = $export->name;

        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $name) !== 1) {
            return $this->refuse($class->name, "'{$name}' is not a kebab-case name");
        }

        if ($export->dataType !== null && $export->dataType !== 'json') {
            return $this->refuse($class->name, "dataType takes only 'json': a form without nested fields is already 'form', and superforms reads no nested data from FormData");
        }

        if (array_filter($export->maps, fn (mixed $path): bool => ! is_string($path)) !== []) {
            return $this->refuse($class->name, 'maps lists field paths as strings');
        }

        return $name;
    }

    private function refuse(string $class, string $reason): null
    {
        $this->refusals[] = new ExportRefused($class, '(form)', self::ATTRIBUTE, $reason);

        return null;
    }

    /**
     * @return list<string>
     */
    private function classesIn(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $namespace = '';
        $classes = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';

                for ($j = $i + 1; isset($tokens[$j]) && ! $tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$j]->text;
                    }
                }
            }

            if (! $token->is(T_CLASS) || $this->previous($tokens, $i)?->is([T_DOUBLE_COLON, T_NEW]) === true) {
                continue;
            }

            $name = $this->next($tokens, $i);

            if ($name?->is(T_STRING) === true) {
                $classes[] = ltrim($namespace.'\\'.$name->text, '\\');
            }
        }

        return $classes;
    }

    /** @param list<\PhpToken> $tokens */
    private function previous(array $tokens, int $i): ?\PhpToken
    {
        while (--$i >= 0) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function next(array $tokens, int $i): ?\PhpToken
    {
        while (isset($tokens[++$i])) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
