<?php

namespace PetarSpasic\LaravelHouse\Kanban\Hooks;

use PetarSpasic\LaravelHouse\Kanban\Code\EnvWriter;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\GitStore;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/**
 * Claude Code WorktreeCreate (`claude -w`, isolated subagents, background sessions). A name that is a card in
 * doing or review returns that card's worktree; so does the oldest pending spawn the PreToolUse hook recorded when the main
 * session spawned a kanban agent (an isolated subagent's name is generated, so the spawn record carries the card);
 * otherwise `.claude/worktrees/<name>` on `worktree-<name>` from local main, dependencies copied, `.env` with a
 * slot, no containers. The realpath is the last stdout line.
 */
final class WorktreeCreate
{
    private const SPAWN_TTL = 120;

    /** Claude Code names an isolated subagent's worktree `agent-` + its agent id. */
    public const ISOLATED_AGENT = '/^agent-a[0-9a-f]{16}$/';

    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private ?Store $store = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  {name, cwd, …}
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public function handle(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return ['stdout' => '', 'stderr' => "worktree-create: no name in the payload\n", 'exit' => 1];
        }
        try {
            if (($card = $this->cardWorktree($name)) !== null) {
                [$id, $path] = $card;

                return ['stdout' => $path."\n", 'stderr' => "kanban: {$name} is card {$id}; entering its worktree\n", 'exit' => 0];
            }
            if (preg_match(self::ISOLATED_AGENT, $name) === 1 && ($spawn = $this->pendingSpawn()) !== null) {
                [$id, $path, $type] = $spawn;

                return ['stdout' => $path."\n", 'stderr' => "kanban: {$name} is the {$type} spawned for {$id}; entering its worktree\n", 'exit' => 0];
            }
            $worktrees = new Worktrees($this->paths, $this->config);
            $slug = EnvWriter::name($name);
            $path = $worktrees->createNamed($slug);
            $stderr = "kanban: worktree {$path} on worktree-{$slug} from ".$worktrees->mainBranch()."\n";
            $entry = $worktrees->prepare($path, 'worktree-'.$slug, null);
            if ($entry !== null) {
                $stderr .= "kanban: .env for {$entry['project']} slot {$entry['slot']} (no containers; `vendor/bin/kanban stack up` starts them)\n";
            }

            return ['stdout' => $path."\n", 'stderr' => $stderr, 'exit' => 0];
        } catch (Throwable $e) {
            return ['stdout' => '', 'stderr' => "kanban worktree-create: {$e->getMessage()}\n", 'exit' => 1];
        }
    }

    /**
     * Claims the oldest spawn record younger than SPAWN_TTL (unlink is the atomic claim); expired ones are dropped.
     *
     * @return array{0: string, 1: string, 2: string}|null the card id, its worktree realpath and the agent type
     */
    private function pendingSpawn(): ?array
    {
        $records = [];
        foreach (glob($this->paths->runtime('spawns').'/*.json') ?: [] as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            $records[$file] = is_array($record) ? $record : [];
        }
        uasort($records, fn (array $a, array $b) => ($a['at'] ?? 0) <=> ($b['at'] ?? 0));

        foreach ($records as $file => $record) {
            if (! @unlink($file) || microtime(true) - (float) ($record['at'] ?? 0) > self::SPAWN_TTL) {
                continue;
            }
            if (($card = $this->cardWorktree((string) ($record['card'] ?? ''))) !== null) {
                return [...$card, (string) ($record['agent_type'] ?? 'agent')];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null the card id and its worktree realpath */
    private function cardWorktree(string $name): ?array
    {
        if (! $this->paths->hasBoard()) {
            return null;
        }
        $id = Ids::normalize($name);
        if (! Ids::isValid($id)) {
            return null;
        }
        $card = ($this->store ??= new GitStore($this->paths, $this->config))->snapshot()->card($id);
        $relative = $card?->work()['worktree'] ?? null;
        if ($card === null || ! in_array($card->stage(), ['doing', 'review'], true) || $relative === null) {
            return null;
        }
        $path = realpath($this->paths->main.'/'.$relative);

        return $path === false ? null : [$card->id(), $path];
    }
}
