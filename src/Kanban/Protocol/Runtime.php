<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Console\StartCommand;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Support\AgentStates;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;

/**
 * Per-machine runtime files under `.git/laravel-house/`: agent records (mtime = heartbeat), staged and applied
 * reports/verdicts, and the write-ahead inbox of hook payloads.
 */
final class Runtime
{
    public const AGENT_DAYS = 14;

    public const APPLIED_DAYS = 7;

    private const SPAWN_SECONDS = 300;

    /** @var list<array<string, mixed>>|null every agent record, read once per instance until one is written or removed */
    private ?array $agentCache = null;

    public function __construct(public readonly Paths $paths, private readonly int $staleMinutes = Snapshot::DEFAULT_STALE_MINUTES) {}

    /** @return array<string, mixed>|null */
    public function agent(string $agentId): ?array
    {
        if (! self::validAgentId($agentId)) {
            return null;
        }

        return self::readJson($this->paths->agents($agentId));
    }

    /** Writes the agent record (atomic; refreshes the heartbeat). @param  array<string, mixed>  $agent */
    public function saveAgent(array $agent): void
    {
        $agent += ['agent_type' => null, 'card' => null, 'worktree' => null, 'bound_at' => null, 'stopped_at' => null, 'stop_blocks' => 0];
        self::writeJson($this->paths->agents((string) $agent['agent_id']), $agent);
        $this->agentCache = null;
    }

    /** @return list<array<string, mixed>> every agent record, each with `beat` (heartbeat unix time) */
    public function agents(): array
    {
        if ($this->agentCache !== null) {
            return $this->agentCache;
        }
        $agents = [];
        foreach (glob($this->paths->agents().'/*.json') ?: [] as $file) {
            $agent = self::readJson($file);
            if ($agent !== null) {
                $agents[] = $agent + ['agent_id' => basename($file, '.json'), 'beat' => (int) @filemtime($file)];
            }
        }

        return $this->agentCache = $agents;
    }

    /** @param  array<string, mixed>  $agent  a record from agents() */
    public function state(array $agent): string
    {
        if (! empty($agent['stopped_at'])) {
            return 'stopped';
        }

        return AgentStates::isStale((int) ($agent['beat'] ?? 0), $this->staleMinutes) ? 'stale' : 'live';
    }

    /**
     * Latest agent of a type bound to a card, or null.
     *
     * @return array<string, mixed>|null
     */
    public function agentFor(string $cardId, ?string $type = null): ?array
    {
        $latest = null;
        foreach ($this->agents() as $agent) {
            if (($agent['card'] ?? null) === $cardId && ($type === null || ($agent['agent_type'] ?? null) === $type)
                && ($latest === null || $agent['beat'] > $latest['beat'])) {
                $latest = $agent;
            }
        }

        return $latest;
    }

    /**
     * Stale agents (heartbeat older than stale_after_minutes) are marked stopped.
     *
     * @return list<string> agent ids marked
     */
    public function markStale(): array
    {
        $marked = [];
        foreach ($this->agents() as $agent) {
            if ($this->state($agent) === 'stale') {
                $beat = $agent['beat'];
                unset($agent['beat']);
                $agent['stopped_at'] = Clock::now();
                $agent['stop_reason'] = 'stale';
                $this->saveAgent($agent);
                @touch($this->paths->agents((string) $agent['agent_id']), $beat);
                $marked[] = (string) $agent['agent_id'];
            }
        }

        return $marked;
    }

    /**
     * Removes runtime files nothing reads any more: stopped agents, applied reports, an expired lease, unclaimed spawns, and
     * staged files of cards that are gone or finished, and start marks of cards gone, finished or at work under another start.
     *
     * @return int files removed
     */
    public function prune(Snapshot $snapshot): int
    {
        $removed = 0;
        $this->agentCache = null;
        $drop = function (string $file, int $maxAge) use (&$removed): bool {
            if ((int) @filemtime($file) < time() - $maxAge && @unlink($file)) {
                $removed++;

                return true;
            }

            return false;
        };

        foreach ($this->agents() as $agent) {
            if (! empty($agent['stopped_at'])) {
                $drop($this->paths->agents((string) $agent['agent_id']), self::AGENT_DAYS * 86400);
            }
        }
        foreach (glob($this->paths->applied('*.json')) ?: [] as $file) {
            $drop($file, self::APPLIED_DAYS * 86400);
        }
        $drop($this->paths->leaseFile(), Lease::IDLE_SECONDS);
        foreach (glob($this->paths->worktrees().'/.copying/*') ?: [] as $dir) {
            if ((int) @filemtime($dir) < time() - 3600) {
                (new Process(['rm', '-rf', $dir]))->run();
                $removed++;
            }
        }
        foreach (glob($this->paths->runtime('spawns').'/*.json') ?: [] as $file) {
            $drop($file, self::SPAWN_SECONDS);
        }
        foreach ([...glob($this->paths->runtime('runs/*.json')) ?: [], ...glob($this->paths->runtime('runs/*.log')) ?: []] as $file) {
            $drop($file, self::APPLIED_DAYS * 86400);
        }
        foreach (glob($this->paths->runtime(StartCommand::STARTED).'/*') ?: [] as $file) {
            $card = $snapshot->card(basename($file));
            // kept in backlog or ready, where a claim of this checkout may yet arrive (its mark comes before it, and this
            // clone may not have pulled it), and at work under a start it lists
            $kept = in_array($card?->stage(), ['backlog', 'ready'], true) || (in_array($card?->stage(), ['doing', 'review'], true)
                && in_array($card->work()['started'] ?? null, StartCommand::marks($this->paths, $card->id()), true));
            $kept || $drop($file, -1);
        }
        foreach (glob($this->paths->staged('*.json')) ?: [] as $file) {
            $card = $snapshot->card(strstr(basename($file), '.', true) ?: '');
            if ($card === null || in_array($card->stage(), ['done', 'dropped'], true)) {
                $drop($file, 86400);
            }
        }

        $this->agentCache = null;

        return $removed;
    }

    public function stagedFile(string $cardId, string $kind): string
    {
        return $this->paths->staged("{$cardId}.{$kind}.json");
    }

    /** @return array<string, mixed>|null */
    public function staged(string $cardId, string $kind): ?array
    {
        return self::readJson($this->stagedFile($cardId, $kind));
    }

    /** Keeps why the staged item was last refused (a gate's tail cut at Gates::TAIL_CHARS) inside the staged file. */
    public function noteRefusal(string $cardId, string $kind, string $reason): void
    {
        $item = $this->staged($cardId, $kind);
        if ($item === null) {
            return;
        }
        $reason = trim($reason);
        if (strlen($reason) > Gates::TAIL_CHARS + 500) {
            $reason = mb_strcut($reason, 0, Gates::TAIL_CHARS + 500).'…';
        }
        $item['refused'] = ['at' => Clock::now(), 'reason' => $reason];
        self::writeJson($this->stagedFile($cardId, $kind), $item);
    }

    /**
     * Why the staged item was last refused, or null when nothing is staged or nothing refused it.
     *
     * @return array{at: string, reason: string}|null
     */
    public function refusal(string $cardId, string $kind): ?array
    {
        $refused = $this->staged($cardId, $kind)['refused'] ?? null;

        return is_array($refused) && is_string($refused['reason'] ?? null) ? ['at' => (string) ($refused['at'] ?? ''), 'reason' => $refused['reason']] : null;
    }

    /** @return list<array{card: string, kind: string, file: string}> */
    public function stagedAll(): array
    {
        $items = [];
        foreach (glob($this->paths->staged('*.json')) ?: [] as $file) {
            if (preg_match('/^(.+)\.(report|verdict)\.json$/', basename($file), $m)) {
                $items[] = ['card' => $m[1], 'kind' => $m[2], 'file' => $file];
            }
        }

        return $items;
    }

    /** @param  array<string, mixed>  $item */
    public function stage(array $item, string $kind): void
    {
        self::writeJson($this->stagedFile((string) $item['card'], $kind), $item);
    }

    public function appliedFile(string $cardId, string $kind, string $hash): string
    {
        return $this->paths->applied("{$cardId}.{$hash}.{$kind}.json");
    }

    /**
     * Applied items of a card, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function applied(string $cardId, string $kind): array
    {
        $items = [];
        foreach (glob($this->paths->applied("{$cardId}.*.{$kind}.json")) ?: [] as $file) {
            if (($item = self::readJson($file)) !== null) {
                $items[] = $item;
            }
        }
        usort($items, fn (array $a, array $b) => ($b['staged_at'] ?? '') <=> ($a['staged_at'] ?? ''));

        return $items;
    }

    /** Moves a staged item to applied/ (kept per hash). */
    public function markApplied(string $cardId, string $kind, string $hash): void
    {
        $from = $this->stagedFile($cardId, $kind);
        $to = $this->appliedFile($cardId, $kind, $hash);
        if (! is_dir(dirname($to))) {
            @mkdir(dirname($to), 0775, true);
        }
        if (is_file($from)) {
            rename($from, $to);
        }
    }

    /** Write-ahead copy of a hook payload; the caller holds the returned lock until done. @return array{0: string, 1: Lock|null} */
    public function inbox(string $event, string $raw): array
    {
        $file = $this->paths->inbox(sprintf('%s-%s-%s.json', str_replace([':', '.', '+'], '', Clock::now()), $event, bin2hex(random_bytes(3))));
        Json::write($file, json_encode(['event' => $event, 'received_at' => Clock::now(), 'raw' => $raw], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        return [$file, Lock::try($file)];
    }

    /**
     * Inbox leftovers nobody is processing; each comes locked for the caller.
     *
     * @return list<array{file: string, event: string, payload: array<string, mixed>, lock: Lock}>
     */
    public function inboxLeftovers(): array
    {
        $items = [];
        foreach (glob($this->paths->inbox('*.json')) ?: [] as $file) {
            $lock = Lock::try($file);
            if ($lock === null || ! is_file($file)) {
                continue;
            }
            $entry = self::readJson($file);
            $payload = is_array($entry) ? json_decode((string) ($entry['raw'] ?? ''), true) : null;
            $items[] = ['file' => $file, 'event' => (string) ($entry['event'] ?? ''), 'payload' => is_array($payload) ? $payload : [], 'lock' => $lock];
        }

        return $items;
    }

    public static function validAgentId(string $agentId): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $agentId) === 1 && ! str_contains($agentId, '..');
    }

    /** @return array<string, mixed>|null */
    public static function readJson(string $file): ?array
    {
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function writeJson(string $file, array $data): void
    {
        Json::write($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
