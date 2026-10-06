<?php

namespace PetarSpasic\LaravelHouse\Kanban\Hooks;

use PetarSpasic\LaravelHouse\Kanban\Console\RunCommand;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * Claude Code SessionEnd: a session that ends (an exit, a logout, `/clear`) frees the lease it holds, so the next session
 * orchestrates without a takeover, unless a `kanban run` still drives the board. A crash ends no session: its lease
 * frees itself after 15 idle minutes.
 */
final class SessionEnd
{
    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private ?Store $store = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  {session_id, reason, …}
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public function handle(array $payload): array
    {
        $session = (string) ($payload['session_id'] ?? '');
        // a `kanban run` it started may outlive it (or a /clear), and drives the board under that session's lease
        if ($session !== '' && RunCommand::running($this->paths) === null) {
            (new Lease($this->paths))->release(new Actor('main', $session));
        }

        return ['stdout' => '', 'stderr' => '', 'exit' => 0];
    }
}
