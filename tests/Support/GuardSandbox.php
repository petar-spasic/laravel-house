<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A temp main repository with a board worktree (docs/kanban), two card worktrees
 * and the runtime dir, driven through the real bin/kanban-guard.
 */
final class GuardSandbox
{
    public const DOING = 'ACME-7K2M9Q';

    public const REVIEW = 'ACME-A1B2C3';

    public const READY = 'ACME-K9M2P1';

    public readonly string $main;

    public readonly string $root;

    private static ?self $shared = null;

    /**
     * One read-mostly sandbox for table-driven cases: `worker-agent` is bound to the doing card,
     * `evaluator-agent` to the review card.
     */
    public static function shared(): self
    {
        if (self::$shared === null) {
            self::$shared = new self;
            self::$shared->bind('worker-agent', 'kanban-worker', self::DOING);
            self::$shared->bind('evaluator-agent', 'kanban-evaluator', self::REVIEW);
        }

        return self::$shared;
    }

    /**
     * Runs a table case: $actor is main | worker | evaluator | other, or `worker:<agent id>` for another worker.
     *
     * @param  array<string, mixed>  $input
     * @return array{decision: ?string, reason: ?string, out: string, ms: float}
     */
    public function case(string $actor, string $tool, array $input, ?string $cwd = null): array
    {
        [$type, $agentId] = array_pad(explode(':', $actor, 2), 2, null);

        return $this->guard($type, $tool, $input, $cwd, $agentId ?? ($type === 'main' ? null : $type.'-agent'));
    }

    /**
     * @param  array<string, mixed>  $kanban  overrides merged into kanban.json
     */
    public function __construct(array $kanban = [])
    {
        $this->root = realpath(sys_get_temp_dir()).'/kanban-guard-'.bin2hex(random_bytes(6));
        $this->main = $this->root.'/app';
        mkdir($this->main, 0777, true);
        mkdir($this->root.'/outside');

        $this->git('init -q -b main');
        $this->git('config user.email test@example.com');
        $this->git('config user.name Test');
        $this->git('config commit.gpgsign false');
        file_put_contents($this->main.'/README.md', "app\n");
        file_put_contents($this->main.'/boost.json', json_encode(['skills' => ['kanban']]));
        file_put_contents($this->main.'/.env', "DB_PORT=5435\n");
        file_put_contents($this->main.'/.gitignore', "/docs/kanban/\n/.claude/worktrees/\n.env\n");
        $this->git('add README.md .gitignore');
        $this->git('commit -q -m init');

        $this->git('worktree add -q --orphan -b kanban docs/kanban');
        $this->json('docs/kanban/kanban.json', array_replace_recursive([
            'version' => 1, 'key' => 'ACME', 'id_length' => 6, 'stale_after_minutes' => 20,
        ], $kanban));
        $this->json('docs/kanban/project/work/board.json', ['title' => 'Work', 'kind' => 'work']);
        $this->card(self::DOING, 'doing', '.claude/worktrees/acme-7k2m9q');
        $this->card(self::REVIEW, 'review', '.claude/worktrees/acme-a1b2c3');
        $this->card(self::READY, 'ready', null);

        $this->git('worktree add -q -b kanban/acme-7k2m9q-x .claude/worktrees/acme-7k2m9q');
        $this->git('worktree add -q -b kanban/acme-a1b2c3-y .claude/worktrees/acme-a1b2c3');
        file_put_contents($this->wt(self::DOING).'/.env', "DB_PORT=21011\n");
        file_put_contents($this->wt(self::REVIEW).'/.env', "DB_PORT=5435\n");
        mkdir($this->main.'/.git/laravel-house/agents', 0777, true);
    }

    public function __destruct()
    {
        (new Process(['rm', '-rf', $this->root]))->run();
    }

    public function wt(string $card): string
    {
        return $this->main.'/.claude/worktrees/'.strtolower($card);
    }

    /**
     * Replaces {main}, {wt}, {review}, {board}, {outside} in a string.
     */
    public function expand(string $text): string
    {
        return strtr($text, [
            '{main}' => $this->main,
            '{wt}' => $this->wt(self::DOING),
            '{review}' => $this->wt(self::REVIEW),
            '{board}' => $this->main.'/docs/kanban',
            '{outside}' => $this->root.'/outside',
        ]);
    }

    public function card(string $id, string $stage, ?string $worktree): void
    {
        $this->json("docs/kanban/project/work/{$id}.json", [
            'id' => $id, 'type' => 'feature', 'title' => "Card {$id}", 'stage' => $stage,
            'work' => $worktree === null ? null : ['branch' => 'kanban/'.strtolower($id), 'worktree' => $worktree],
        ]);
    }

    public function bind(string $agentId, string $type, ?string $card, int $ageMinutes = 0, ?string $stoppedAt = null): string
    {
        $file = $this->main."/.git/laravel-house/agents/{$agentId}.json";
        file_put_contents($file, json_encode([
            'agent_id' => $agentId, 'agent_type' => $type, 'card' => $card,
            'worktree' => $card === null ? null : '.claude/worktrees/'.strtolower($card),
            'bound_at' => '2026-09-28T19:00:00.000+00:00', 'stopped_at' => $stoppedAt, 'stop_blocks' => 0,
        ]));
        touch($file, time() - $ageMinutes * 60);

        return $file;
    }

    /**
     * Runs the guard. $actor: main | worker | evaluator | other (general-purpose).
     *
     * @param  array<string, mixed>  $input
     * @return array{decision: ?string, reason: ?string, out: string, ms: float}
     */
    public function guard(string $actor, string $tool, array $input, ?string $cwd = null, ?string $agentId = null): array
    {
        $payload = [
            'session_id' => 'sess-1',
            'transcript_path' => $this->root.'/transcript.jsonl',
            'cwd' => $cwd === null ? $this->main : $this->expand($cwd),
            'permission_mode' => 'default',
            'hook_event_name' => 'PreToolUse',
            'tool_name' => $tool,
            'tool_input' => array_map(fn ($v) => is_string($v) ? $this->expand($v) : $v, $input),
            'tool_use_id' => 'toolu_1',
        ];

        if ($actor !== 'main') {
            $payload['agent_id'] = $agentId ?? $actor.'-agent';
            $payload['agent_type'] = match ($actor) {
                'worker' => 'kanban-worker',
                'evaluator' => 'kanban-evaluator',
                default => 'general-purpose',
            };
        }

        return $this->raw(json_encode($payload));
    }

    /**
     * @return array{decision: ?string, reason: ?string, out: string, ms: float}
     */
    public function raw(string $stdin): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/kanban-guard'], $this->main);
        $process->setInput($stdin);
        $start = hrtime(true);
        $process->run();
        $ms = (hrtime(true) - $start) / 1e6;

        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('kanban-guard failed: '.$process->getErrorOutput());
        }

        $out = trim($process->getOutput());
        $json = $out === '' ? null : json_decode($out, true);

        return [
            'decision' => $json['hookSpecificOutput']['permissionDecision'] ?? null,
            'reason' => $json['hookSpecificOutput']['permissionDecisionReason'] ?? null,
            'out' => $out,
            'ms' => $ms,
        ];
    }

    public function git(string $args, ?string $cwd = null): Process
    {
        $process = Process::fromShellCommandline('git '.$args, $cwd ?? $this->main);
        $process->mustRun();

        return $process;
    }

    private function json(string $relative, array $data): void
    {
        $file = $this->main.'/'.$relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
