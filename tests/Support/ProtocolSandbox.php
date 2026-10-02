<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A Sandbox with the board installed, cards started through `kanban start` (no Docker: no compose file) and
 * the Claude Code hook payloads of tests/Support/payloads/hooks.
 */
final class ProtocolSandbox
{
    public const SESSION = '5f0c1d2e-0000-4000-8000-00000000abcd';

    public readonly string $main;

    /** @param  array<string, mixed>  $config  config/kanban.php of the project */
    public function __construct(public readonly Sandbox $sandbox, array $config = ['gates' => ['report' => []]])
    {
        $this->main = realpath($sandbox->root) ?: $sandbox->root;
        $this->config($config);
    }

    public static function create(array $config = ['gates' => ['report' => []]]): self
    {
        $sandbox = Sandbox::create();
        $sandbox->install('ACME');

        return new self($sandbox, $config);
    }

    /** @param  array<string, mixed>  $config */
    public function config(array $config): void
    {
        @mkdir($this->main.'/config', 0775, true);
        file_put_contents($this->main.'/config/kanban.php', '<?php return '.var_export($config, true).";\n");
    }

    /**
     * A ready card with the given criteria, started: in doing with its worktree.
     *
     * @param  list<string>  $accept
     * @return array{0: string, 1: string} id, worktree realpath
     */
    public function started(string $title, array $accept = ['It renders', 'It is tested']): array
    {
        $id = $this->sandbox->card($title, ['--body=Build it', '--stage=ready', ...array_map(fn ($a) => "--accept={$a}", $accept)]);
        $out = $this->sandbox->ok(['start', $id]);
        if (preg_match('/^worktree (.+)$/m', $out, $m) !== 1) {
            throw new RuntimeException("no worktree in: {$out}");
        }

        return [$id, realpath($m[1]) ?: $m[1]];
    }

    /** Writes a file in the worktree and commits it. */
    public function commit(string $worktree, string $file, string $content = "x\n", string $message = 'work'): string
    {
        @mkdir(dirname($worktree.'/'.$file), 0775, true);
        file_put_contents($worktree.'/'.$file, $content);
        $this->git($worktree, 'add', $file);
        $this->git($worktree, 'commit', '-q', '-m', $message);

        return trim($this->git($worktree, 'rev-parse', 'HEAD'));
    }

    public function git(string $cwd, string ...$args): string
    {
        $process = new Process(['git', ...$args], $cwd, ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C']);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $args).': '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * A hook payload fixture with {main}, {cwd}, {agent}, {type}, {session} filled in.
     *
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>  $extra  merged into the payload
     */
    public function payload(string $name, array $vars = [], array $extra = []): string
    {
        $json = (string) file_get_contents(dirname(__FILE__).'/payloads/hooks/'.$name.'.json');
        $json = strtr($json, [
            '{main}' => $this->main, '{cwd}' => $vars['cwd'] ?? $this->main, '{agent}' => $vars['agent'] ?? 'a4d2c0ffee',
            '{type}' => $vars['type'] ?? 'kanban-worker', '{session}' => $vars['session'] ?? self::SESSION,
        ]);

        return json_encode(array_replace(json_decode($json, true, 64, JSON_THROW_ON_ERROR), $extra), JSON_UNESCAPED_SLASHES);
    }

    /** `kanban hook <event>` with the payload on stdin. */
    public function hook(string $event, string $payload, array $env = [], ?string $cwd = null): Process
    {
        return $this->sandbox->kanban(['hook', $event], $env, $cwd, $payload);
    }

    /** The real PreToolUse guard: EnterWorktree binds the agent to the card of $worktree. */
    public function enter(string $worktree, string $agent = 'a4d2c0ffee', string $type = 'kanban-worker'): void
    {
        $process = new Process([PHP_BINARY, Sandbox::package().'/bin/kanban-guard'], $this->main);
        $process->setInput($this->payload('enter-worktree', ['cwd' => $worktree, 'agent' => $agent, 'type' => $type]));
        $process->mustRun();
    }

    /** Runs `kanban …` from inside a worktree (as an agent does). */
    public function in(string $worktree, array $args, array $env = [], ?string $input = null): Process
    {
        return $this->sandbox->kanban($args, $env, $worktree, $input);
    }

    /** @return array<string, mixed>|null */
    public function agent(string $agentId): ?array
    {
        $file = $this->runtime("agents/{$agentId}.json");

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function runtime(string $relative = ''): string
    {
        return $this->main.'/.git/laravel-kanban'.($relative === '' ? '' : '/'.$relative);
    }

    /** @return array<string, mixed> */
    public function card(string $id): array
    {
        return $this->sandbox->read($id);
    }
}
