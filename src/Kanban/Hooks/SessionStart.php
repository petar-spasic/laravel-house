<?php

namespace PetarSpasic\LaravelHouse\Kanban\Hooks;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Brief;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\OldBoard;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/**
 * SessionStart: attach when needed, flush the journal, retry the inbox, mark stale agents, export KANBAN_SESSION,
 * then print the brief — or, in a card's worktree, the card context as additionalContext with a session title.
 */
final class SessionStart
{
    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private readonly Store $store,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public function handle(array $payload, ?string $ownInbox = null): array
    {
        $stderr = [];
        if (! $this->paths->inRepo) {
            return ['stdout' => "kanban: not installed\n", 'stderr' => '', 'exit' => 0];
        }
        if (! $this->paths->hasBoard()) {
            try {
                (new Bootstrap($this->paths, $this->config))->attach();
            } catch (KanbanException) {
                return ['stdout' => "kanban: not installed\n", 'stderr' => '', 'exit' => 0];
            }
        }
        $session = is_string($payload['session_id'] ?? null) && $payload['session_id'] !== '' ? $payload['session_id'] : null;
        $envFile = getenv('CLAUDE_ENV_FILE');
        if ($session !== null && is_string($envFile) && $envFile !== '') {
            file_put_contents($envFile, 'export KANBAN_SESSION='.escapeshellarg($session)."\n", FILE_APPEND);
        }

        try {
            $this->store->flush(new Actor('hook'));
            $this->store->maybeSync();
            foreach ((new SubagentStop($this->paths, $this->config, $this->store))->retry($ownInbox) as $line) {
                $stderr[] = $line;
            }
        } catch (KanbanException $e) {
            $stderr[] = 'kanban: '.$e->getMessage();
        }
        try {
            $snapshot = $this->store->snapshot();
        } catch (OldBoard $e) {
            return ['stdout' => $e->getMessage()."\n", 'stderr' => self::join($stderr), 'exit' => 0];
        }
        $runtime = new Runtime($this->paths, $snapshot->staleMinutes());
        $runtime->markStale();

        $cwd = is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : $this->paths->cwd;
        $context = new Context($this->paths, $this->config);
        $card = str_starts_with(realpath($cwd) ?: $cwd, $this->paths->worktrees().'/') ? $context->cardAt($snapshot, $cwd) : null;
        if ($card !== null) {
            $json = ['hookSpecificOutput' => [
                'hookEventName' => 'SessionStart',
                'additionalContext' => implode("\n", $context->lines($card, $snapshot, $card->stage() === 'review')),
                'sessionTitle' => "{$card->id()} {$card->title()}",
            ]];
            $stdout = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        } else {
            $stdout = implode("\n", (new Brief($this->store, $this->paths, $this->config))->lines($session))."\n";
        }

        $this->tidy($runtime, $snapshot);

        return ['stdout' => $stdout, 'stderr' => self::join($stderr), 'exit' => 0];
    }

    /**
     * Housekeeping once the output is ready: file pruning is quick; removing idle agent worktrees can wait on docker,
     * so it runs detached and never holds the hook.
     */
    private function tidy(Runtime $runtime, Snapshot $snapshot): void
    {
        try {
            $runtime->prune($snapshot);
            $last = $this->paths->runtime('reclaim.last');
            if ((is_file($last) && (int) filemtime($last) > time() - 3600) || (new WorktreeRemove($this->paths, $this->config))->idleAgentWorktrees() === []) {
                return;
            }
            $this->paths->ensureRuntime();
            @touch($last);
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
            $bin = dirname(__DIR__, 3).'/bin/kanban';
            exec(sprintf('cd %s && nohup %s %s sweep --reclaim > /dev/null 2>&1 &', escapeshellarg($this->paths->main), escapeshellarg($php), escapeshellarg($bin)));
        } catch (Throwable) {
        }
    }

    /** @param  list<string>  $lines */
    private static function join(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}
