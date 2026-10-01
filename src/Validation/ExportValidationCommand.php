<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use Illuminate\Console\Command;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\Factory;
use InvalidArgumentException;
use Throwable;

final class ExportValidationCommand extends Command
{
    protected $signature = 'validation:export
        {--check : Compare with the files on disk and fail on any difference}
        {--path=frontend/src/lib/validation/generated : Output directory, relative to the project root}';

    protected $description = 'Generate the Zod schemas of #[ExportValidation] FormRequests for the SvelteKit frontend';

    /** @var list<ExportRefused> */
    private array $refusals = [];

    private RequestPipeline $pipeline;

    private FormReader $reader;

    private RuleNormaliser $normaliser;

    private TypeScriptRenderer $typescript;

    private Translator $translator;

    public function handle(Factory $validation, Translator $translator): int
    {
        $discovery = new FormDiscovery;
        $forms = $discovery->discover(app_path());
        $this->refusals = $discovery->refusals;
        $relative = trim((string) $this->option('path'), '/');
        $directory = base_path($relative);
        $root = explode('/', $relative)[0];

        if (! is_dir(base_path($root))) {
            if ($forms === [] && $this->refusals === []) {
                $this->info('no exported forms');

                return self::SUCCESS;
            }

            foreach ($forms as $class) {
                $this->refusals[] = new ExportRefused($class, '(form)', '#[ExportValidation]', "no {$root}/ directory; validation export serves the spa module");
            }

            return $this->refused();
        }

        $expected = $forms === [] ? [] : $this->render($forms, $validation, $translator);

        if ($this->refusals !== []) {
            return $this->refused();
        }

        $actual = $this->existing($directory);

        if ($this->refusals !== []) {
            return $this->refused();
        }

        return $this->option('check') ? $this->check($expected, $actual) : $this->write($directory, $expected, $actual);
    }

    /**
     * @param  array<string, class-string>  $forms
     * @return array<string, string> file => contents
     */
    private function render(array $forms, Factory $validation, Translator $translator): array
    {
        try {
            $this->pipeline = RequestPipeline::read($this->laravel);
        } catch (Throwable $e) {
            $this->refusals[] = new ExportRefused('(app)', 'HTTP kernel', class_basename($e), $e->getMessage());

            return [];
        }

        $locales = $this->locales();
        $default = (string) config('app.locale');
        $this->translator = $translator;
        $this->reader = new FormReader($this->laravel, $translator);
        $this->normaliser = new RuleNormaliser((fn (): array => [...$this->extensions, ...$this->implicitExtensions, ...$this->dependentExtensions])->call($validation));
        $this->typescript = new TypeScriptRenderer;
        $files = ['_runtime.ts' => $this->typescript->runtime($locales, in_array($default, $locales, true) ? $default : $locales[0], $this->pipeline->emptyToNull)];

        foreach ($forms as $name => $class) {
            try {
                $files["{$name}.ts"] = $this->form($class, $locales);
            } catch (ExportRefused $e) {
                $this->refusals[] = $e;
            } catch (Throwable $e) {
                $this->refusals[] = new ExportRefused($class, '(form)', class_basename($e), $e->getMessage());
            }
        }

        return $files;
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $locales
     */
    private function form(string $class, array $locales): string
    {
        $read = $this->reader->read($class, $locales);
        $parsed = null;

        foreach ($locales as $locale) {
            $next = $this->parse($class, $read['rules'][$locale]);

            if ($parsed !== null && $this->sources($next) !== $this->sources($parsed)) {
                throw new ExportRefused($class, '(form)', 'rules()', 'rules() depends on the locale');
            }

            $parsed ??= $next;
        }

        $parsed = $this->timeless($class, $parsed, $this->parse($class, $read['later']['rules']));

        if ($read['later']['messages'] !== $read['messages'][$locales[0]] || $read['later']['attributes'] !== $read['attributes'][$locales[0]]) {
            $this->refusals[] = new ExportRefused($class, '(form)', 'messages()', 'messages() or attributes() depends on the current time');
        }

        $schema = (new FieldTranslator($this->pipeline))->translate($class, $parsed, $read['failOnUnknownFields']);

        array_push($this->refusals, ...$schema->refusals);

        $messages = [];
        $attributes = [];
        $original = $this->translator->getLocale();

        foreach ($locales as $locale) {
            $this->translator->setLocale($locale);

            try {
                $renderer = MessageRenderer::make($this->translator, $this->rendererRules($schema), $read['messages'][$locale], $read['attributes'][$locale]);

                foreach ($schema->messages as $key => $request) {
                    try {
                        $text = $renderer->render($request, 0);

                        if (str_contains($request['attribute'], '*') && $renderer->render($request, 1) !== $text) {
                            throw new InvalidArgumentException("the message changes with the index; give {$request['attribute']} a name in attributes()");
                        }

                        $messages[$locale][$key] = $text;
                    } catch (InvalidArgumentException $e) {
                        $this->refusals[] = new ExportRefused($class, $request['attribute'], substr($key, strlen($request['attribute']) + 1), $e->getMessage());
                    }
                }

                foreach (array_keys($schema->rendererRules) as $path) {
                    $attributes[$locale][$path] = $renderer->attributeName($path);
                }
            } finally {
                $this->translator->setLocale($original);
            }
        }

        $notes = [
            ...array_map(fn (string $hook): string => "(form): {$hook}()", $read['hooks']),
            ...($this->pipeline->skipCallbacks ? ['(form): TrimStrings::skipWhen() is registered, so trimming may differ'] : []),
        ];

        return $this->typescript->form($schema, $messages, $attributes, $notes);
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $rules  rules() as the form returns it
     * @return array<string, list<ParsedRule>>
     */
    private function parse(string $class, array $rules): array
    {
        $parsed = [];

        foreach ($rules as $field => $fieldRules) {
            try {
                $parsed[(string) $field] = $this->normaliser->normalise($class, (string) $field, $fieldRules);
            } catch (ExportRefused $e) {
                $this->refusals[] = $e;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, list<ParsedRule>>  $parsed
     * @return array<string, list<string>>
     */
    private function sources(array $parsed): array
    {
        return array_map(fn (array $list): array => array_map(fn (ParsedRule $r): string => $r->source, $list), $parsed);
    }

    /**
     * A rule whose text moves with the clock (`before:`.now()->subYears(18)) is left to the server: the
     * module would carry the export date and turn stricter than Laravel the day after.
     *
     * @param  class-string  $class
     * @param  array<string, list<ParsedRule>>  $now
     * @param  array<string, list<ParsedRule>>  $later  the same rules read under a later clock
     * @return array<string, list<ParsedRule>>
     */
    private function timeless(string $class, array $now, array $later): array
    {
        foreach ($now as $field => $rules) {
            if (count($rules) !== count($later[$field] ?? [])) {
                throw new ExportRefused($class, $field, '(field)', 'rules() depends on the current time');
            }

            foreach ($rules as $i => $rule) {
                if ($rule->source !== $later[$field][$i]->source) {
                    $now[$field][$i] = ParsedRule::serverOnly(($rule->name ?: strtok($rule->source, ':')).' (relative to the current date)');
                }
            }
        }

        if (array_diff_key($later, $now) !== []) {
            throw new ExportRefused($class, '(form)', 'rules()', 'rules() depends on the current time');
        }

        return $now;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rendererRules(FormSchema $schema): array
    {
        $rules = $schema->rendererRules;

        foreach ($schema->messages as $request) {
            if ($request['password'] ?? false) {
                $rules[$request['attribute']][] = 'string';
            }
        }

        return array_map(fn (array $names): array => array_values(array_unique($names)), $rules);
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        $locales = [(string) config('app.locale'), (string) config('app.fallback_locale')];

        foreach (glob(lang_path('*/validation.php')) ?: [] as $file) {
            $locales[] = basename(dirname($file));
        }

        $locales = array_values(array_unique(array_filter($locales)));
        sort($locales, SORT_STRING);

        return $locales;
    }

    /**
     * @return array<string, string> file => contents of what is in the directory now
     */
    private function existing(string $directory): array
    {
        $files = [];

        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $file) {
            $path = "{$directory}/{$file}";

            // Dotfiles (.gitkeep, .DS_Store) are never modules: left alone.
            if (str_starts_with($file, '.') || ! is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (! str_starts_with($contents, TypeScriptRenderer::HEADER)) {
                $this->refusals[] = new ExportRefused('(directory)', $this->relative($path), '(file)', 'not generated by validation:export; move it out of the generated directory');

                continue;
            }

            $files[$file] = $contents;
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     */
    private function check(array $expected, array $actual): int
    {
        $problems = [];

        foreach ($expected as $file => $contents) {
            if (! isset($actual[$file])) {
                $problems[$file] = 'missing';
            } elseif ($actual[$file] !== $contents) {
                $problems[$file] = 'changed';
            }
        }

        foreach (array_diff_key($actual, $expected) as $file => $_) {
            $problems[$file] = 'stale';
        }

        ksort($problems, SORT_STRING);

        foreach ($problems as $file => $problem) {
            $this->line("{$problem} {$file}");
        }

        if ($problems !== []) {
            $this->error('run php artisan validation:export');

            return self::FAILURE;
        }

        $this->info('validation export is current');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     */
    private function write(string $directory, array $expected, array $actual): int
    {
        if ($expected !== [] && ! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $report = [];

        foreach ($expected as $file => $contents) {
            if (($actual[$file] ?? null) === $contents) {
                $report[$file] = 'unchanged';
            } else {
                file_put_contents("{$directory}/{$file}", $contents);
                $report[$file] = 'written';
            }
        }

        foreach (array_diff_key($actual, $expected) as $file => $_) {
            unlink("{$directory}/{$file}");
            $report[$file] = 'removed';
        }

        ksort($report, SORT_STRING);

        foreach ($report as $file => $action) {
            $this->line("{$action} {$file}");
        }

        if ($report === []) {
            $this->info('no exported forms');
        }

        return self::SUCCESS;
    }

    private function refused(): int
    {
        $lines = array_map(fn (ExportRefused $e): string => $e->getMessage(), $this->refusals);
        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);

        foreach ($lines as $line) {
            $this->error($line);
        }

        $this->line('Nothing was written.');

        return self::FAILURE;
    }

    private function relative(string $path): string
    {
        return ltrim(substr($path, strlen(base_path())), '/');
    }
}
