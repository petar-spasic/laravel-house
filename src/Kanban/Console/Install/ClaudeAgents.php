<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

/**
 * Writes `.claude/agents/kanban-{worker,evaluator}.md` with `model` and `effort` from `kanban.agents.<role>`; a file
 * without our marker is someone else's and is left alone.
 */
final class ClaudeAgents extends Step
{
    public const AGENTS = ['kanban-worker', 'kanban-evaluator'];

    public const MARKER = '<!-- laravel-house:kanban-agent';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        $lines = [];
        foreach (self::AGENTS as $agent) {
            $file = self::file($agent);
            $current = $this->read($file);
            $stub = $this->expected($agent);
            if ($current === $stub) {
                $lines[] = "{$file} ok";
            } elseif ($current !== null && ! str_contains($current, self::MARKER)) {
                $lines[] = "kept {$file}: not ours (no laravel-house kanban marker); move it away to install ours";
            } elseif ($dryRun) {
                $lines[] = "would write {$file}";
            } else {
                $this->write($file, $stub);
                $lines[] = ($current === null ? 'wrote ' : 'updated ').$file;
            }
        }

        return $lines;
    }

    public function check(): array
    {
        $results = [];
        foreach (self::AGENTS as $agent) {
            $file = self::file($agent);
            $current = $this->read($file);
            $results[] = match (true) {
                $current === null => ['fail', "{$file} missing (run `vendor/bin/kanban doctor --fix`)"],
                ! str_contains($current, self::MARKER) => ['warn', "{$file} is not the house kanban agent (no marker)"],
                $current !== $this->expected($agent) => ['warn', "{$file} outdated (run `vendor/bin/kanban doctor --fix`)"],
                default => ['ok', $file],
            };
        }

        return $results;
    }

    /** The stub with the configured model and effort in its frontmatter (the stub's own values when unset). */
    private function expected(string $agent): string
    {
        [$frontmatter, $body] = explode("\n---\n", self::stub("claude/agents/{$agent}.md"), 2);
        foreach (['model', 'effort'] as $key) {
            $value = $this->config['agents'][substr($agent, 7)][$key] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9._\[\]-]+$/', $value)) {
                $frontmatter = (string) preg_replace("/^{$key}: .*$/m", "{$key}: {$value}", $frontmatter);
            }
        }

        return $frontmatter."\n---\n".$body;
    }

    private static function file(string $agent): string
    {
        return ".claude/agents/{$agent}.md";
    }
}
