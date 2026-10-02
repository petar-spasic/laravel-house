<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use JsonException;
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The always-loaded protocol text: a marked block in the root CLAUDE.md, written only by adopting the board (the house
 * ships no Boost guideline, so a project without the board carries none of it). With Boost (`boost.json`), the house
 * package joins `packages`, so `boost:update` copies the `kanban` skill into .claude/skills.
 */
final class Guidelines extends Step
{
    public const PACKAGE = 'petar-spasic/laravel-house';

    public const START = '<!-- laravel-house:kanban:start -->';

    public const END = '<!-- laravel-house:kanban:end -->';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        return [...$this->block($dryRun), ...($this->read('boost.json') !== null ? $this->boost($dryRun) : [])];
    }

    public function check(): array
    {
        $claude = (string) $this->read('CLAUDE.md');
        $results = [match (true) {
            ! str_contains($claude, self::START) => ['fail', 'CLAUDE.md kanban block missing (run `vendor/bin/kanban doctor --fix`)'],
            $this->withBlock($claude) !== $claude => ['warn', 'CLAUDE.md kanban block outdated (run `vendor/bin/kanban doctor --fix`)'],
            default => ['ok', 'CLAUDE.md kanban block'],
        }];
        $boost = $this->read('boost.json');
        if ($boost !== null) {
            $packages = (array) (json_decode($boost, true)['packages'] ?? []);
            $results[] = match (true) {
                ! in_array(self::PACKAGE, $packages, true) => ['fail', 'boost.json packages lacks '.self::PACKAGE.' (run `vendor/bin/kanban doctor --fix`)'],
                ! is_file($this->path('.claude/skills/kanban/SKILL.md')) => ['warn', 'kanban skill not in .claude/skills yet (run `php artisan boost:update`)'],
                default => ['ok', 'kanban skill in .claude/skills'],
            };
        }

        return $results;
    }

    /** @return list<string> */
    private function boost(bool $dryRun): array
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
        if (in_array(self::PACKAGE, $packages, true)) {
            return ['boost.json ok'];
        }
        if ($dryRun) {
            return ['would add '.self::PACKAGE.' to boost.json packages', 'would run php artisan boost:update --no-interaction'];
        }
        $config->packages = [...$packages, self::PACKAGE];
        $this->write('boost.json', self::json($config, $current));

        return ['boost.json packages += '.self::PACKAGE, $this->boostUpdate()];
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
            return ["would {$would}"];
        }
        $this->write('CLAUDE.md', $next);

        return [$did];
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
