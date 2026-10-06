<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Hooks\SessionEnd;
use PetarSpasic\LaravelHouse\Kanban\Hooks\SessionStart;
use PetarSpasic\LaravelHouse\Kanban\Hooks\SubagentStart;
use PetarSpasic\LaravelHouse\Kanban\Hooks\SubagentStop;
use PetarSpasic\LaravelHouse\Kanban\Hooks\WorktreeCreate;
use PetarSpasic\LaravelHouse\Kanban\Hooks\WorktreeRemove;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'kanban:hook')]
class HookCommand extends Command
{
    protected $signature = 'kanban:hook {event : session-start|session-end|subagent-start|subagent-stop|stop|worktree-create|worktree-remove}';

    protected $description = 'Claude Code hook handler (reads the hook JSON from stdin)';

    private const EVENTS = [
        'session-start' => SessionStart::class,
        'session-end' => SessionEnd::class,
        'subagent-start' => SubagentStart::class,
        'subagent-stop' => SubagentStop::class,
        // a headless card session (`kanban run`) stops with Stop, not SubagentStop
        'stop' => SubagentStop::class,
        'worktree-create' => WorktreeCreate::class,
        'worktree-remove' => WorktreeRemove::class,
    ];

    protected function perform(): int
    {
        $event = (string) $this->argument('event');
        $class = self::EVENTS[$event] ?? null;
        $raw = $class === null ? '' : (string) stream_get_contents(STDIN);
        $payload = json_decode($raw, true);
        if ($class === null || ! is_array($payload)) {
            // Exit 1, never 2: Claude Code treats a hook's exit 2 as a blocking error.
            $this->fault($class === null
                ? "kanban hook: unknown event '{$event}' (".implode(', ', array_keys(self::EVENTS)).')'
                : "kanban hook {$event}: the payload on stdin is not a JSON object");

            return 1;
        }

        $paths = $this->paths();
        if (! $paths->inRepo && is_string($payload['cwd'] ?? null) && ($found = Paths::discover($payload['cwd']))->inRepo) {
            $this->laravel->instance(Paths::class, $found);
            $paths = $found;
        }
        if (! $paths->inRepo) {
            if ($event === 'session-start') {
                $this->say('kanban: not installed');
            }

            return self::SUCCESS;
        }
        if (! class_exists($class)) {
            $this->fault("kanban hook {$event}: handler not installed");

            return self::SUCCESS;
        }

        [$inbox, $lock] = (new Runtime($paths))->inbox($event, $raw);
        $handler = null;
        try {
            $handler = new $class($paths, $this->config(), $this->laravel->make(Store::class));
            $result = $class === SessionStart::class ? $handler->handle($payload, $inbox) : $handler->handle($payload);
        } catch (Throwable $e) {
            $lock?->release();
            if ($handler instanceof SubagentStop) {
                try {
                    $handler->failed($payload, $e->getMessage());
                } catch (Throwable) {
                }
            }
            $this->fault("kanban hook {$event} failed: ".$e->getMessage().' (payload kept in '.$paths->relative($inbox).'; `vendor/bin/kanban apply` retries it)');

            return 1;
        }
        @unlink($inbox);
        $lock?->release();

        if ($result['stdout'] !== '') {
            $this->output->write($result['stdout'], false, OutputInterface::OUTPUT_RAW);
        }
        if ($result['stderr'] !== '') {
            foreach (explode("\n", rtrim($result['stderr'], "\n")) as $line) {
                $this->fault($line);
            }
        }

        return $result['exit'];
    }
}
