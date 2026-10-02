<?php

namespace PetarSpasic\Kanban\Console\Install;

use JsonException;
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The always-loaded protocol text. With Boost (`boost.json`): our package joins `packages` and `boost:update` renders
 * resources/boost into CLAUDE.md and .claude/skills. Without Boost: a marked block in the root CLAUDE.md.
 */
final class Guidelines extends Step
{
    public const PACKAGE = 'petar-spasic/laravel-kanban';

    public const START = '<!-- laravel-kanban:start -->';

    public const END = '<!-- laravel-kanban:end -->';

    public const HEADING = '## Kanban (petar-spasic/laravel-kanban)';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        return $this->read('boost.json') !== null ? $this->boost($dryRun, $force) : $this->block($dryRun);
    }

    public function check(): array
    {
        $claude = (string) $this->read('CLAUDE.md');
        $boost = $this->read('boost.json');
        if ($boost !== null) {
            $packages = (array) (json_decode($boost, true)['packages'] ?? []);
            if (! in_array(self::PACKAGE, $packages, true)) {
                return [['fail', 'boost.json packages lacks '.self::PACKAGE.' (run `vendor/bin/kanban doctor --fix`)']];
            }

            return [str_contains($claude, self::HEADING)
                ? ['ok', 'Boost guideline in CLAUDE.md']
                : ['warn', 'Boost guideline not in CLAUDE.md yet (run `php artisan boost:update`)']];
        }
        if (! str_contains($claude, self::START)) {
            return [['fail', 'CLAUDE.md kanban block missing (run `vendor/bin/kanban doctor --fix`)']];
        }

        return [$this->withBlock($claude) === $claude
            ? ['ok', 'CLAUDE.md kanban block']
            : ['warn', 'CLAUDE.md kanban block outdated (run `vendor/bin/kanban doctor --fix`)']];
    }

    /** @return list<string> */
    private function boost(bool $dryRun, bool $force): array
    {
        $current = (string) $this->read('boost.json');
        try {
            $config = json_decode($current, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['skipped boost.json: not valid JSON ('.$e->getMessage().')'];
        }
        if (! $config instanceof stdClass) {
            return ['skipped boost.json: the top level is not an object'];
        }
        $packages = is_array($config->packages ?? null) ? $config->packages : [];
        $added = ! in_array(self::PACKAGE, $packages, true);
        $claude = (string) $this->read('CLAUDE.md');
        $stale = str_contains($claude, self::START);
        if (! $added && ! $force && ! $stale && str_contains($claude, self::HEADING)) {
            return ['boost.json ok'];
        }
        if ($dryRun) {
            return array_values(array_filter([
                $added ? 'would add '.self::PACKAGE.' to boost.json packages' : null,
                $stale ? 'would remove the CLAUDE.md kanban block (Boost renders the guideline)' : null,
                'would run php artisan boost:update --no-interaction',
            ]));
        }
        $lines = [];
        if ($added) {
            $config->packages = [...$packages, self::PACKAGE];
            $this->write('boost.json', self::json($config, $current));
            $lines[] = 'boost.json packages += '.self::PACKAGE;
        }
        if ($stale) {
            $this->write('CLAUDE.md', (string) preg_replace($this->blockPattern(), '', $claude));
            $lines[] = 'removed the CLAUDE.md kanban block (Boost renders the guideline)';
        }
        $lines[] = $this->boostUpdate();

        return $lines;
    }

    /** @return list<string> */
    private function block(bool $dryRun): array
    {
        $current = $this->read('CLAUDE.md');
        $next = $this->withBlock((string) $current);
        if ($next === $current) {
            return ['CLAUDE.md kanban block ok'];
        }
        [$would, $did] = match (true) {
            $current === null => ['create CLAUDE.md with the kanban block', 'created CLAUDE.md with the kanban block'],
            str_contains($current, self::START) => ['update the kanban block in CLAUDE.md', 'updated the kanban block in CLAUDE.md'],
            default => ['add the kanban block to CLAUDE.md', 'added the kanban block to CLAUDE.md'],
        };
        if ($dryRun) {
            return ["would {$would} (no boost.json)"];
        }
        $this->write('CLAUDE.md', $next);

        return ["{$did} (no boost.json)"];
    }

    /** CLAUDE.md with our block current: replaced in place, else before Boost's guidelines, else appended. */
    private function withBlock(string $claude): string
    {
        $block = self::START."\n".self::stub('claude/claude-md-block.md').self::END."\n";
        if (str_contains($claude, self::START)) {
            return (string) preg_replace_callback($this->blockPattern(), fn () => $block, $claude, 1);
        }
        $boost = strpos($claude, '<laravel-boost-guidelines>');
        if ($boost !== false) {
            return substr($claude, 0, $boost).$block."\n".substr($claude, $boost);
        }
        if ($claude === '') {
            return $block;
        }

        return rtrim($claude, "\n")."\n\n".$block;
    }

    private function blockPattern(): string
    {
        return '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/')."\n?/s";
    }

    private function boostUpdate(): string
    {
        if (! is_file($this->path('artisan'))) {
            return 'run `php artisan boost:update` in the project (no artisan here)';
        }
        $process = new Process([PHP_BINARY, 'artisan', 'boost:update', '--no-interaction'], $this->paths->main, null, null, 300);
        try {
            $process->run();
        } catch (Throwable $e) {
            return 'boost:update failed ('.$e->getMessage().'); run `php artisan boost:update` by hand';
        }
        if (! $process->isSuccessful()) {
            $tail = implode(' ', array_slice(array_filter(explode("\n", trim($process->getErrorOutput().$process->getOutput()))), -3));

            return "boost:update exited {$process->getExitCode()}: {$tail}; run `php artisan boost:update` by hand";
        }

        return 'ran php artisan boost:update';
    }
}
