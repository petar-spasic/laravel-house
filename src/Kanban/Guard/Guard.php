<?php

namespace PetarSpasic\LaravelHouse\Kanban\Guard;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Claude Code PreToolUse hook. It never allows or denies; it keeps the runtime files the rest of kanban reads:
 * the agent's heartbeat (agents/<id>.json mtime), its binding to a card at EnterWorktree, and the spawn record
 * that WorktreeCreate hands to the next isolated kanban agent. Any error is swallowed.
 */
final class Guard
{
    private const WORKER = 'kanban-worker';

    private const EVALUATOR = 'kanban-evaluator';

    private const HELD_SECONDS = 300;

    public function run(string $raw): void
    {
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($payload)) {
                $this->handle($payload);
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        $cwd = is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : (string) getcwd();
        $projectDir = getenv('CLAUDE_PROJECT_DIR');
        $main = self::findMain($cwd) ?? (is_string($projectDir) && $projectDir !== '' ? self::findMain($projectDir) : null);
        if ($main === null) {
            return;
        }

        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
        $agentId = is_string($payload['agent_id'] ?? null) ? $payload['agent_id'] : '';

        if ($agentId === '') {
            if (in_array($payload['tool_name'] ?? null, ['Agent', 'Task'], true)) {
                $this->recordSpawn($main, $input);
            }

            return;
        }

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $agentId) || str_contains($agentId, '..')) {
            return;
        }

        $file = $main.'/.git/laravel-kanban/agents/'.$agentId.'.json';
        $binding = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_file($file)) {
            touch($file);
        }

        $type = $payload['agent_type'] ?? null;
        if (($payload['tool_name'] ?? null) === 'EnterWorktree' && ($type === self::WORKER || $type === self::EVALUATOR)) {
            $this->bind($main, $file, $agentId, $type, is_array($binding) ? $binding : [], $input, $cwd);
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $input
     */
    private function bind(string $main, string $file, string $agentId, string $type, array $binding, array $input, string $cwd): void
    {
        $path = $input['path'] ?? $input['worktree_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return;
        }

        $target = self::canonical($path[0] === '/' ? $path : $cwd.'/'.$path);
        $stage = $type === self::WORKER ? 'doing' : 'review';

        foreach (self::cards($main) as $card) {
            $worktree = $card['work']['worktree'] ?? null;
            if (($card['stage'] ?? null) !== $stage || ! is_string($worktree) || $worktree === '') {
                continue;
            }
            if (self::canonical($worktree[0] === '/' ? $worktree : $main.'/'.$worktree) !== $target) {
                continue;
            }
            if (! empty($binding['card']) && $binding['card'] !== $card['id']) {
                return;
            }
            if (self::heldByAnother($main, $agentId, $type, $card['id'])) {
                return;
            }

            self::write($file, [
                'agent_id' => $agentId,
                'agent_type' => $type,
                'card' => $card['id'],
                'worktree' => $worktree,
                'bound_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
                'stopped_at' => null,
                'stop_blocks' => (int) ($binding['stop_blocks'] ?? 0),
            ]);

            return;
        }
    }

    /**
     * True when a different agent of the same type is bound to the card, has not stopped and beat in the last five minutes (a live
     * agent beats on every tool call; a crashed one must not keep its replacement out until stale_after_minutes).
     */
    private static function heldByAnother(string $main, string $agentId, string $type, string $card): bool
    {
        $stale = self::HELD_SECONDS;

        foreach (glob($main.'/.git/laravel-kanban/agents/*.json') ?: [] as $file) {
            if (basename($file, '.json') === $agentId || (int) @filemtime($file) < time() - $stale) {
                continue;
            }
            $other = json_decode((string) @file_get_contents($file), true);
            if (is_array($other) && empty($other['stopped_at']) && ($other['agent_type'] ?? null) === $type && strtoupper((string) ($other['card'] ?? '')) === strtoupper($card)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordSpawn(string $main, array $input): void
    {
        $type = $input['subagent_type'] ?? null;
        if ($type !== self::WORKER && $type !== self::EVALUATOR) {
            return;
        }

        $stage = $type === self::WORKER ? 'doing' : 'review';
        $cards = self::cards($main);
        $named = [];
        preg_match_all('/\b[A-Z][A-Z0-9]{1,9}-[0-9A-Z]{4,12}\b/i', (string) ($input['prompt'] ?? ''), $m);
        foreach (array_unique(array_map('strtoupper', $m[0])) as $id) {
            if (($cards[$id]['stage'] ?? null) === $stage && ! empty($cards[$id]['work']['worktree'])) {
                $named[] = $cards[$id];
            }
        }
        if (count($named) !== 1) {
            return;
        }

        self::write($main.'/.git/laravel-kanban/spawns/'.$named[0]['id'].'.json', [
            'card' => $named[0]['id'],
            'agent_type' => $type,
            'worktree' => $named[0]['work']['worktree'],
            'at' => microtime(true),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cards(string $main): array
    {
        $cards = [];
        foreach (glob($main.'/docs/kanban/*/*/*.json') ?: [] as $file) {
            $card = basename($file) === 'board.json' ? null : json_decode((string) file_get_contents($file), true);
            if (is_array($card) && isset($card['id'])) {
                $cards[strtoupper($card['id'])] = $card;
            }
        }

        return $cards;
    }

    /**
     * The main checkout containing $dir: the directory whose .git is a directory,
     * reached through commondir when $dir is inside a linked worktree.
     */
    private static function findMain(string $dir): ?string
    {
        $dir = realpath($dir) ?: null;

        while ($dir !== null) {
            $git = $dir.'/.git';

            if (is_dir($git)) {
                return $dir;
            }

            if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m)) {
                $gitdir = trim($m[1]);
                $gitdir = $gitdir[0] === '/' ? $gitdir : $dir.'/'.$gitdir;
                $commondir = @file_get_contents($gitdir.'/commondir');
                $common = $commondir === false ? false : realpath(str_starts_with(trim($commondir), '/') ? trim($commondir) : $gitdir.'/'.trim($commondir));

                return $common !== false && basename($common) === '.git' ? dirname($common) : $dir;
            }

            $parent = dirname($dir);
            $dir = $parent === $dir ? null : $parent;
        }

        return null;
    }

    /** Absolute path with symlinks resolved as far as the path exists. */
    private static function canonical(string $path): string
    {
        $rest = '';
        while ($path !== '/' && ! file_exists($path)) {
            $rest = '/'.basename($path).$rest;
            $path = dirname($path);
        }

        return rtrim((realpath($path) ?: $path), '/').$rest;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write(string $file, array $data): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $tmp = $file.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        rename($tmp, $file);
    }
}
