<?php

namespace PetarSpasic\Kanban\Guard;

use ErrorException;
use Throwable;
use UnexpectedValueException;

/**
 * Claude Code PreToolUse hook: reads the payload, applies Rules and prints at most one decision.
 * The main session fails open on an internal error; subagents fail closed.
 */
final class Guard
{
    private bool $done = false;

    private bool $subagent = false;

    public function run(string $raw): void
    {
        $this->subagent = (bool) preg_match('/"agent_id"\s*:\s*"[^"]/', $raw);

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if (! $this->done && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->fail($error['message']);
            }
        });

        set_error_handler(function (int $level, string $message, string $file, int $line): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new UnexpectedValueException('the hook payload is not a JSON object');
            }
            [$decision, $reason] = $this->decide($payload);
            if ($decision !== null) {
                $this->emit($decision, $reason);
            }
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }

        $this->done = true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: string}
     */
    private function decide(array $payload): array
    {
        $agentId = is_string($payload['agent_id'] ?? null) && $payload['agent_id'] !== '' ? $payload['agent_id'] : null;
        $this->subagent = $agentId !== null;
        $cwd = is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : (string) getcwd();
        $projectDir = getenv('CLAUDE_PROJECT_DIR');
        $main = Locations::findMain($cwd) ?? (is_string($projectDir) && $projectDir !== '' ? Locations::findMain($projectDir) : null);

        if ($main === null) {
            return [null, ''];
        }

        $agentType = is_string($payload['agent_type'] ?? null) ? $payload['agent_type'] : null;
        $actor = match (true) {
            $agentId === null => 'main',
            $agentType === Rules::WORKER => 'worker',
            $agentType === Rules::EVALUATOR => 'evaluator',
            default => 'other',
        };

        $binding = null;
        if ($agentId !== null) {
            if (! preg_match('/^[A-Za-z0-9_.-]+$/', $agentId) || str_contains($agentId, '..')) {
                throw new UnexpectedValueException('invalid agent_id');
            }
            $file = $main.'/.git/laravel-kanban/agents/'.$agentId.'.json';
            if (is_file($file)) {
                touch($file);
                $binding = json_decode((string) file_get_contents($file), true);
                $binding = is_array($binding) ? $binding : null;
            }
        }

        $home = getenv('HOME') ?: '/root';
        $rules = new Rules(new Locations($main), $actor, $agentId, $agentType, $binding, (new Locations($main))->canonical($cwd), $home);
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];

        return match ($payload['tool_name'] ?? null) {
            'Bash', 'Monitor' => $rules->bash((string) ($input['command'] ?? '')),
            'Edit', 'Write' => $rules->edit((string) ($input['file_path'] ?? '')),
            'NotebookEdit' => $rules->edit((string) ($input['notebook_path'] ?? '')),
            'EnterWorktree' => $rules->enterWorktree($input),
            'ExitWorktree' => $rules->exitWorktree(),
            'Agent', 'Task' => $rules->agent($input),
            default => [null, ''],
        };
    }

    private function fail(string $message): void
    {
        if ($this->subagent) {
            $this->emit('deny', "kanban guard error: {$message}; ask the main session.");
        }
        $this->done = true;
    }

    private function emit(string $decision, string $reason): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        echo json_encode(['hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => $decision,
            'permissionDecisionReason' => $reason,
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }
}
