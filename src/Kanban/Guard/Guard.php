<?php

namespace PetarSpasic\LaravelHouse\Kanban\Guard;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Claude Code PreToolUse hook. It keeps the runtime files the rest of kanban reads: the agent's heartbeat
 * (agents/<id>.json mtime), its binding to a card at EnterWorktree, and the spawn record that WorktreeCreate hands
 * to the next isolated kanban agent. For a bound worker, planner or evaluator it routes every shell command but a plain
 * `vendor/bin/kanban` one into the card's container (`updatedInput`), and fences its file tools to the card's
 * directory, a planner's writes to the card's `.tmp`: the only thing it denies. Any error is swallowed and prints nothing.
 */
final class Guard
{
    /** Seconds after which a spawn record no agent has claimed is dropped by the next spawn of its type. */
    private const SPAWN_UNCLAIMED = 20;

    private const WORKER = 'kanban-worker';

    private const EVALUATOR = 'kanban-evaluator';

    private const PLANNER = 'kanban-planner';

    /** Each kanban agent and the stage of the cards it works on. */
    private const STAGES = [self::WORKER => 'doing', self::EVALUATOR => 'review', self::PLANNER => 'planning'];

    private const HELD_SECONDS = 300;

    /**
     * The one command that stays on this machine: `vendor/bin/kanban` (host docker, the board, main's runtime), by any
     * path, without environment assignments. It runs as main's binary: the card's own copy is the agent's to change.
     */
    private const HOST = '#^\s*(?:php\s+)?(?:[A-Za-z0-9_.~/-]*/)?vendor/bin/kanban(?=\s|$)#';

    /** File tools and the input key that holds their path (Glob and Grep default to the cwd). */
    private const FILE_TOOLS = ['Read' => 'file_path', 'Edit' => 'file_path', 'Write' => 'file_path', 'NotebookEdit' => 'notebook_path', 'Glob' => 'path', 'Grep' => 'path'];

    private const WRITES = ['Edit', 'Write', 'NotebookEdit'];

    /** A kanban agent's type: a worker, planner or evaluator. */
    private static function isAgent(mixed $type): bool
    {
        return is_string($type) && isset(self::STAGES[$type]);
    }

    /** The command a card agent's shell runs through; ClaudeSettings allows exactly this prefix. */
    public static function exec(string $main): string
    {
        return self::quoted($main.'/vendor/bin/kanban-exec');
    }

    /** Main's kanban binary, which a card agent's kanban command is rewritten to; ClaudeSettings allows it. */
    public static function kanban(string $main): string
    {
        return self::quoted($main.'/vendor/bin/kanban');
    }

    /** $command with its leading kanban path (and `php`) replaced by main's binary, or null when it is not a plain kanban command. */
    public static function hostKanban(string $main, string $command): ?string
    {
        if (preg_match(self::HOST, $command, $m) !== 1 || self::compound($command)) {
            return null;
        }

        return self::kanban($main).substr($command, strlen($m[0]));
    }

    /**
     * More than one plain command: an operator or a redirect outside quotes, or a command substitution outside single
     * quotes. Anything chained to a host command would run on the host too; quoted text (a note, evidence) and a
     * heredoc with a quoted delimiter that ends the command are data.
     */
    public static function compound(string $command): bool
    {
        $quote = null;
        for ($i = 0, $n = strlen($command); $i < $n; $i++) {
            $c = $command[$i];
            if ($quote === "'") {
                $quote = $c === "'" ? null : $quote;
            } elseif ($c === '\\') {
                $i++;
            } elseif ($c === '`' || ($c === '$' && ($command[$i + 1] ?? '') === '(')) {
                return true;
            } elseif ($quote === '"') {
                $quote = $c === '"' ? null : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '<' && preg_match('/\G<<-?\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*\n/', $command, $m, 0, $i) === 1) {
                // a heredoc with a quoted delimiter is literal stdin; it must be the whole rest of the command
                $body = substr($command, $i + strlen($m[0]));

                return preg_match('/^(?:.*\n)*?'.$m[2].'[ \t]*\n?$/', $body) !== 1;
            } elseif (str_contains(";&|<>\n\r", $c)) {
                return true;
            }
        }

        return $quote !== null;
    }

    private static function quoted(string $path): string
    {
        return preg_match('#^[A-Za-z0-9_.,:/@%+=-]+$#', $path) ? $path : escapeshellarg($path);
    }

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
        // a headless card session (`kanban run`) has no agent_id: it is bound by its session id
        if ($agentId === '' && self::isAgent($payload['agent_type'] ?? null) && is_string($payload['session_id'] ?? null)) {
            $agentId = $payload['session_id'];
        }

        if ($agentId === '') {
            if (in_array($payload['tool_name'] ?? null, ['Agent', 'Task'], true)) {
                $this->recordSpawn($main, $input);
            }

            return;
        }

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $agentId) || str_contains($agentId, '..')) {
            return;
        }

        $file = $main.'/.git/laravel-house/agents/'.$agentId.'.json';
        $binding = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_file($file)) {
            touch($file);
        }

        $type = $payload['agent_type'] ?? null;
        $tool = $payload['tool_name'] ?? null;
        if ($tool === 'EnterWorktree' && self::isAgent($type)) {
            $this->bind($main, $file, $agentId, $type, is_array($binding) ? $binding : [], $input, $cwd);
        } elseif (($tool === 'Bash' || $tool === 'Monitor') && is_array($binding)) {
            $this->route($main, $binding, $input, $cwd);
        } elseif (isset(self::FILE_TOOLS[$tool]) && is_array($binding)) {
            $this->fence($main, $binding, (string) $tool, $input, $cwd);
        }
    }

    /**
     * A bound kanban agent's file tool outside its card's directory is denied, and so is a write into the card's `.git` or
     * `.claude`, and a planner's write anywhere but the card's `.tmp`. Reads may also reach Claude Code's own temp
     * directory and the skill directories.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $input
     */
    private function fence(string $main, array $binding, string $tool, array $input, string $cwd): void
    {
        $worktree = $binding['worktree'] ?? null;
        if (! is_string($worktree) || $worktree === '' || ! self::isAgent($binding['agent_type'] ?? null)) {
            return;
        }
        $root = self::canonical($worktree[0] === '/' ? $worktree : $main.'/'.$worktree);
        $key = self::FILE_TOOLS[$tool];
        $given = $input[$key] ?? null;
        // a kanban agent works in its card: a relative path, or none, means the card's
        $path = self::canonical(! is_string($given) || $given === '' ? $root : ($given[0] === '/' ? $given : $root.'/'.$given));
        $inside = fn (string $dir) => $path === $dir || str_starts_with($path, $dir.'/');
        $write = in_array($tool, self::WRITES, true);

        if ($inside($root)) {
            $planner = $binding['agent_type'] === self::PLANNER;
            if (! $write || ($planner ? $inside($root.'/.tmp') : ! $inside($root.'/.git') && ! $inside($root.'/.claude'))) {
                if ($given !== $path && ($given !== null || $tool === 'Glob' || $tool === 'Grep')) {
                    $input[$key] = $path;
                    echo json_encode(['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'updatedInput' => $input]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }

                return;
            }
            $why = $planner ? "{$path}: a planner writes only its plan and question files, in {$root}/.tmp; the code is the worker's"
                : "{$path}: .git and .claude in your card's directory are kanban's; work in the code";
        } else {
            $home = getenv('HOME');
            // Claude Code's own files for this project: task outputs, the scratchpad
            $tmp = rtrim((string) (getenv('CLAUDE_CODE_TMPDIR') ?: sys_get_temp_dir()), '/').'/claude-'.(function_exists('posix_getuid') ? posix_getuid() : getmyuid())
                .'/'.preg_replace('/[^A-Za-z0-9]/', '-', $main);
            $readable = [self::canonical($tmp), self::canonical($main.'/.claude/skills'), ...(is_string($home) && $home !== '' ? [self::canonical($home.'/.claude')] : [])];
            foreach ($readable as $dir) {
                if ($inside($dir) && (! $write || $dir === $readable[0])) {
                    return;
                }
            }
            $why = "{$path} is outside your card's directory {$root}; work only there (scratch files go in {$root}/.tmp)";
        }
        echo json_encode(['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny',
            'permissionDecisionReason' => "kanban: {$why}"]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * A bound kanban agent's command → `<main>/vendor/bin/kanban-exec <container> <cwd> '<command>'`, when its
     * card's stack is up with `agents.shell` = container (the stack record); otherwise a command from outside the card's
     * directory is prefixed with `cd <card> && `. The whole input is returned with only the command replaced, and no
     * permission decision.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $input
     */
    private function route(string $main, array $binding, array $input, string $cwd): void
    {
        $command = $input['command'] ?? null;
        $worktree = $binding['worktree'] ?? null;
        if (! is_string($command) || trim($command) === '' || ! is_string($worktree) || $worktree === ''
            || ! self::isAgent($binding['agent_type'] ?? null)) {
            return;
        }
        if (($host = self::hostKanban($main, $command)) !== null) {
            // one plain command (each part of a chain is checked on its own); --in gives the CLI the agent's card
            $input['command'] = self::kanban($main).' '.self::quoted('--in='.self::canonical($worktree[0] === '/' ? $worktree : $main.'/'.$worktree))
                .substr($host, strlen(self::kanban($main)));
            echo json_encode(['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'updatedInput' => $input]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return;
        }
        $root = self::canonical($worktree[0] === '/' ? $worktree : $main.'/'.$worktree);
        $dir = self::canonical($cwd);
        $inside = $dir === $root || str_starts_with($dir, $root.'/');
        $record = json_decode((string) @file_get_contents($main.'/.git/laravel-house/stacks/'.basename($worktree).'.json'), true);
        $container = $record['container'] ?? null;
        if (($record['shell'] ?? null) !== 'container' || ! is_string($container) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $container)
            || ! is_file($main.'/vendor/bin/kanban-exec')) {
            if ($inside) {
                return;
            }
            // no container: the shell stays on this machine, but in the card's clone, never in main
            $input['command'] = 'cd '.escapeshellarg($root).' && '.$command;
        } else {
            $input['command'] = self::exec($main).' '.$container.' '.escapeshellarg($inside ? $dir : $root).' '.escapeshellarg($command);
        }
        echo json_encode(['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'updatedInput' => $input]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
        $stage = self::STAGES[$type];

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

        foreach (glob($main.'/.git/laravel-house/agents/*.json') ?: [] as $file) {
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
        if (! self::isAgent($type)) {
            return;
        }

        $stage = self::STAGES[$type];
        $cards = self::cards($main);
        $named = [];
        $prompt = (string) ($input['prompt'] ?? '');
        // the spawn line starts `Card <ID>.`; otherwise any one id the prompt names
        preg_match('/^Card ([A-Z][A-Z0-9]{1,9}-[0-9A-Z]{4,12})\./i', $prompt, $lead);
        preg_match_all('/\b[A-Z][A-Z0-9]{1,9}-[0-9A-Z]{4,12}\b/i', $prompt, $m);
        $leading = strtoupper($lead[1] ?? '');
        if (($cards[$leading]['stage'] ?? null) === $stage && ! empty($cards[$leading]['work']['worktree'])) {
            $m[0] = [$leading];
        }
        foreach (array_unique(array_map('strtoupper', $m[0])) as $id) {
            if (($cards[$id]['stage'] ?? null) === $stage && ! empty($cards[$id]['work']['worktree'])) {
                $named[] = $cards[$id];
            }
        }
        if (count($named) !== 1) {
            return;
        }

        // a record its agent has not claimed past SubagentStart's 15 s timeout is from a spawn that never started (declined,
        // cancelled): left, it would bind this spawn's agent to that card
        foreach (glob($main.'/.git/laravel-house/spawns/*.json') ?: [] as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            if (($record['agent_type'] ?? null) === $type && microtime(true) - (float) ($record['at'] ?? 0) > self::SPAWN_UNCLAIMED) {
                @unlink($file);
            }
        }
        self::write($main.'/.git/laravel-house/spawns/'.$named[0]['id'].'.json', [
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
        foreach ([...glob($main.'/docs/kanban/*/*.json') ?: [], ...glob($main.'/docs/kanban/*/*/*.json') ?: []] as $file) {
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
                return self::cloneMain($dir) ?? $dir;
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

    /** A card clone's main checkout (`kanban.main`, see Paths::cloneMainOf), or null. */
    private static function cloneMain(string $dir): ?string
    {
        $config = @file_get_contents($dir.'/.git/config');
        if (! is_string($config) || ! preg_match('/^\[kanban\]\R(?:[ \t]+[^\[\r\n]*\R)*?[ \t]+main[ \t]*=[ \t]*(.+?)[ \t]*$/m', $config, $m)) {
            return null;
        }
        $main = realpath($m[1]);

        return $main !== false && is_dir($main.'/.git') && str_starts_with($dir, $main.'/.claude/worktrees/') ? $main : null;
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
