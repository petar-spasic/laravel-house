<?php

namespace PetarSpasic\Kanban\Hooks;

use PetarSpasic\Kanban\Protocol\Brief;
use PetarSpasic\Kanban\Protocol\Context;
use PetarSpasic\Kanban\Protocol\Runtime;
use PetarSpasic\Kanban\Store\Actor;
use PetarSpasic\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\Kanban\Store\Git\Bootstrap;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Paths;
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
        $this->store->flush(new Actor('hook'));
        foreach ((new SubagentStop($this->paths, $this->config, $this->store))->retry($ownInbox) as $line) {
            $stderr[] = $line;
        }
        $snapshot = $this->store->snapshot();
        $runtime = new Runtime($this->paths, (int) $snapshot->setting('stale_after_minutes', 20));
        $runtime->markStale();
        $runtime->prune($snapshot);
        try {
            (new WorktreeRemove($this->paths, $this->config))->reclaim();
        } catch (Throwable) {
        }

        $session = is_string($payload['session_id'] ?? null) && $payload['session_id'] !== '' ? $payload['session_id'] : null;
        $envFile = getenv('CLAUDE_ENV_FILE');
        if ($session !== null && is_string($envFile) && $envFile !== '') {
            file_put_contents($envFile, 'export KANBAN_SESSION='.escapeshellarg($session)."\n", FILE_APPEND);
        }

        $cwd = is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : $this->paths->cwd;
        $context = new Context($this->paths, $this->config);
        $card = str_starts_with(realpath($cwd) ?: $cwd, $this->paths->worktrees().'/') ? $context->cardAt($snapshot, $cwd) : null;
        if ($card !== null) {
            $json = ['hookSpecificOutput' => [
                'hookEventName' => 'SessionStart',
                'additionalContext' => implode("\n", $context->lines($card, $snapshot, $card->stage() === 'review')),
                'sessionTitle' => "{$card->id()} {$card->title()}",
            ]];

            return ['stdout' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", 'stderr' => self::join($stderr), 'exit' => 0];
        }

        $brief = (new Brief($this->store, $this->paths, $this->config))->lines($session);

        return ['stdout' => implode("\n", $brief)."\n", 'stderr' => self::join($stderr), 'exit' => 0];
    }

    /** @param  list<string>  $lines */
    private static function join(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}
