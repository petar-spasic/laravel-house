<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
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
     * A planned card with the given criteria, started: in doing with its worktree.
     *
     * @param  list<string>  $accept
     * @return array{0: string, 1: string} id, worktree realpath
     */
    public function started(string $title, array $accept = ['It renders', 'It is tested']): array
    {
        $id = $this->sandbox->card($title, Sandbox::withArea(['--body=Build it', '--stage=planning', ...array_map(fn ($a) => "--accept={$a}", $accept)]));
        $this->sandbox->plan($id);
        $out = $this->sandbox->ok(['start', $id]);
        if (preg_match('/^worktree (.+)$/m', $out, $m) !== 1) {
            throw new RuntimeException("no worktree in: {$out}");
        }

        return [$id, realpath($m[1]) ?: $m[1]];
    }

    /**
     * A card with the given criteria in planning, taken for its planner by `kanban start`: still in planning, held, with its clone.
     *
     * @param  list<string>  $accept
     * @return array{0: string, 1: string, 2: string} id, worktree realpath, start's output
     */
    public function planning(string $title, array $accept = ['It renders', 'It is tested'], array $options = []): array
    {
        $id = $this->sandbox->card($title, Sandbox::withArea(['--body=Build it', '--stage=planning', ...array_map(fn ($a) => "--accept={$a}", $accept), ...$options]));
        $out = $this->sandbox->ok(['start', $id]);
        if (preg_match('/^worktree (.+)$/m', $out, $m) !== 1) {
            throw new RuntimeException("no worktree in: {$out}");
        }

        return [$id, realpath($m[1]) ?: $m[1], $out];
    }

    /** Puts the card in review, approved at its branch head (what a report and an approving verdict leave); the head. */
    public function approve(string $id, string $worktree): string
    {
        $head = trim($this->git($worktree, 'rev-parse', 'HEAD'));
        $file = glob($this->main.'/docs/kanban/*/'.$id.'.json')[0];
        $card = json_decode((string) file_get_contents($file), true);
        $card['stage'] = 'review';
        $card['work']['head'] = $head;
        $card['work']['approved'] = ['head' => $head, 'at' => $card['updated']];
        Json::write($file, Json::encode($card, 'card'));
        $this->sandbox->boardGit('commit', '-q', '-am', "{$id} approved (test)");
        // finish reads the card's branch in main
        $this->git($this->main, 'fetch', '-q', $worktree, '+HEAD:refs/heads/'.$card['work']['branch']);

        return $head;
    }

    /**
     * The merge `finish` leaves for the merger of approved $id: the merge clone of main on branch `merge`, the round's
     * pins (`refs/merge-queue/<id>/base` and `/card` in main, `refs/merge/base` and `/card` in the clone) and this
     * checkout's merge.json. conflict: the merge of the card into main's head stopped on conflicts, left in progress.
     * red: it merged clean, and $failure failed on it. The clone.
     *
     * @param  array<string, mixed>  $failure
     * @param  array<string, mixed>  $state  merged over merge.json
     */
    public function merging(string $id, string $phase = 'conflict', array $failure = [], array $state = []): string
    {
        $card = $this->card($id);
        $clone = $this->main.'/.claude/worktrees/_merge';
        if (! is_dir($clone)) {
            $this->git($this->main, 'clone', '-q', $this->main, $clone);
            $this->git($clone, 'config', 'kanban.main', $this->main);
            $this->git($clone, 'config', 'user.name', 'Test User');
            $this->git($clone, 'config', 'user.email', 'test@example.com');
        }
        $base = trim($this->git($this->main, 'rev-parse', 'refs/heads/main'));
        $head = $card['work']['approved']['head'];
        $this->git($this->main, 'update-ref', "refs/merge-queue/{$id}/base", $base);
        $this->git($this->main, 'update-ref', "refs/merge-queue/{$id}/card", $head);
        $this->git($clone, 'fetch', '-q', $this->main, "+refs/merge-queue/{$id}/*:refs/merge/*");
        $this->git($clone, 'checkout', '-q', '-B', 'merge', 'refs/merge/base');
        $merge = new Process(['git', 'merge', '--no-ff', '-q', '-m', "{$id}: {$card['title']}", 'refs/merge/card'], $clone, ['LC_ALL' => 'C']);
        $merge->run();
        $conflicts = array_values(array_filter(explode("\n", trim($this->git($clone, 'diff', '--name-only', '--diff-filter=U')))));
        if (($phase === 'conflict') !== ($conflicts !== [])) {
            throw new RuntimeException("the merge of {$id} ".($conflicts === [] ? 'is clean' : 'conflicts').", not {$phase}");
        }
        $sha = trim($this->git($clone, 'rev-parse', 'HEAD'));
        Runtime::writeJson($this->runtime('merge.json'), array_replace([
            'card' => $id, 'lease' => '9f2c41d07a8b3e65', 'phase' => $phase, 'round' => 1, 'merger_rounds' => 0, 'merger_runs' => 1,
            'base' => $base, 'head' => $head,
        ], $phase === 'conflict' ? ['conflicts' => $conflicts] : ['merge_commit' => $sha, 'checked' => $sha, 'failure' => $failure + [
            'step' => 'suite', 'command' => 'php artisan test', 'exit' => 1, 'tail' => "FAILED  Tests\\Feature\\NotesTest\n1 failed", 'base_rerun' => 'passed',
        ]], ['started' => '2026-10-09T08:00:00.000+00:00'], $state));

        return realpath($clone) ?: $clone;
    }

    /** This checkout's merge.json, or null. @return array<string, mixed>|null */
    public function mergeState(): ?array
    {
        return Runtime::readJson($this->runtime('merge.json'));
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
        return $this->main.'/.git/laravel-house'.($relative === '' ? '' : '/'.$relative);
    }

    /** @return array<string, mixed> */
    public function card(string $id): array
    {
        return $this->sandbox->read($id);
    }
}
