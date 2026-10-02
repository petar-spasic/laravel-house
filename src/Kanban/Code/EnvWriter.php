<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Illuminate\Support\Str;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;

/**
 * The worktree `.env`: main's `.env` minus the managed keys, plus the `stack.env` block with its
 * placeholders resolved ({project}, {name}, {app}, {scheme}, {host} and every `stack.ports` key), and the
 * card stack's own keys: KANBAN_WORKTREE_PATH (the compose file also mounts the worktree there, so an agent's shell
 * runs in the container at the path it sees), and KANBAN_GIT_SSH_COMMAND and KANBAN_GIT_TOKEN empty (only main's
 * stack syncs the board).
 */
final class EnvWriter
{
    public const MARKER = '# laravel-house kanban worktree stack (managed: rewritten by kanban, edit config/kanban.php stack.env instead)';

    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    public function __construct(private readonly string $main, private readonly array $config) {}

    /** `<app>` = slug of main's APP_NAME, else of the main directory's name. */
    public function app(): string
    {
        $name = DotEnv::parse($this->main.'/.env')['APP_NAME'] ?? null;

        return Str::slug((string) ($name ?: basename($this->main))) ?: 'app';
    }

    /** `[a-z0-9-]`, at most 40 characters. */
    public static function name(string $worktree): string
    {
        return trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($worktree))), '-'), 0, 40), '-') ?: 'wt';
    }

    public function project(string $worktree): string
    {
        return $this->resolve((string) ($this->config['stack']['project'] ?? '{app}-wt-{name}'), $this->values($worktree, []));
    }

    /** The URL the LAN opens: LOCAL_APP_URL of the block, else `{scheme}://{host}:{WEB_PORT}`, or null without a web port. */
    public function url(string $worktree, array $ports): ?string
    {
        if (! isset($ports['WEB_PORT'])) {
            return null;
        }
        $template = (string) ($this->config['stack']['env']['LOCAL_APP_URL'] ?? '{scheme}://{host}:{WEB_PORT}');

        return $this->resolve($template, $this->values($worktree, $ports));
    }

    /** @param  array<string, int>  $ports */
    public function write(string $worktree, array $ports): void
    {
        $own = ['KANBAN_WORKTREE_PATH' => realpath($worktree) ?: $worktree, 'KANBAN_GIT_SSH_COMMAND' => '', 'KANBAN_GIT_TOKEN' => ''];
        $managed = array_merge(array_keys($ports), array_keys((array) ($this->config['stack']['env'] ?? [])), array_keys($own));
        $lines = [];
        $source = is_file($this->main.'/.env') ? (string) file_get_contents($this->main.'/.env') : '';
        foreach (preg_split('/\R/', $source) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) && in_array($m[1], $managed, true)) {
                continue;
            }
            $lines[] = $line;
        }
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        $values = $this->values($worktree, $ports);
        $block = [self::MARKER];
        foreach ($ports as $key => $port) {
            $block[] = "{$key}={$port}";
        }
        foreach ((array) ($this->config['stack']['env'] ?? []) as $key => $template) {
            $block[] = $key.'='.self::quote($this->resolve((string) $template, $values));
        }
        foreach ($own as $key => $value) {
            $block[] = $key.'='.self::quote($value);
        }

        Json::write($worktree.'/.env', ($lines === [] ? '' : implode("\n", $lines)."\n\n").implode("\n", $block)."\n");
    }

    /** @return array<string, string> */
    private function values(string $worktree, array $ports): array
    {
        $url = DotEnv::parse($this->main.'/.env')['LOCAL_APP_URL'] ?? null;
        $parts = is_string($url) && $url !== '' ? parse_url($url) : [];
        $host = $this->config['worktrees']['host'] ?? null;
        $values = [
            'app' => $this->app(),
            'name' => self::name($worktree),
            'scheme' => (string) ($parts['scheme'] ?? 'http'),
            'host' => is_string($host) && $host !== '' ? $host : (string) ($parts['host'] ?? 'localhost'),
        ];
        $values['project'] = $this->resolve((string) ($this->config['stack']['project'] ?? '{app}-wt-{name}'), $values);

        return $values + array_map('strval', $ports);
    }

    private function resolve(string $template, array $values): string
    {
        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', fn (array $m) => $values[$m[1]] ?? $m[0], $template);
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:\/@+,-]*$/', $value) ? $value : '"'.addcslashes($value, '"\\$').'"';
    }
}
