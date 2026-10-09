<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeLease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LostClaim;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The beater: one detached `kanban finish --beat=<lease>` per checkout (`merge.beat.lock`) that beats the merge lease
 * every MergeLease::HEARTBEAT_SECONDS whatever the merge is doing, so no step pushes beats itself. It ends once
 * merge.json no longer names the lease, or for IDLE_SECONDS neither a `finish` nor the card's merger ran, so a crashed
 * holder's lease lapses.
 */
final class MergeBeat
{
    public const LOCK = 'merge.beat.lock';

    /** How long the beater outlives the last `finish` or merger of the merge. */
    public const IDLE_SECONDS = 300;

    private const POLL_SECONDS = 5;

    /** How long a new beater waits for the beater of an earlier lease to end: a poll, and a beat in flight. */
    private const WAIT_SECONDS = 30;

    public function __construct(
        private readonly Paths $paths,
        private readonly MergeLease $lease,
        private readonly AgentRun $agents,
    ) {}

    /**
     * Starts the beater of $lease unless it runs. The beater of an earlier lease ends at its next poll, which the new one
     * waits for.
     *
     * @param  array<string, string|false>  $env
     */
    public function ensure(string $lease, array $env = []): void
    {
        if ($this->alive() && @file_get_contents($this->paths->runtime(self::LOCK)) === $lease) {
            return;
        }
        $command = [PHP_BINARY, $this->paths->main.'/vendor/bin/kanban', 'finish', '--beat='.$lease];
        $log = $this->paths->ensureRuntime('merge').'/beat.err';
        Process::fromShellCommandline('setsid nohup '.implode(' ', array_map('escapeshellarg', $command)).' > /dev/null 2> '.escapeshellarg($log).' < /dev/null &',
            $this->paths->main, $env)->mustRun();
    }

    public function alive(): bool
    {
        $lock = $this->lock();
        if ($lock === null) {
            return true;
        }
        fclose($lock);

        return false;
    }

    /** The beater's life: 0 once it has nothing left to beat, or another beater runs. */
    public function loop(string $lease): int
    {
        for ($until = hrtime(true) / 1e9 + self::WAIT_SECONDS; ($lock = $this->lock()) === null && hrtime(true) / 1e9 < $until;) {
            usleep(200_000);
        }
        if ($lock === null) {
            return 0;
        }
        // the lease it beats, for ensure()
        ftruncate($lock, 0);
        fwrite($lock, $lease);
        $state = new MergeState($this->paths);
        $run = new MergeRun($this->paths);
        $idle = null;
        $tried = -INF;
        while (true) {
            $merge = $state->read();
            if (($merge['lease'] ?? null) !== $lease || $merge['phase'] === MergeState::RELEASING) {
                return 0;
            }
            // this process's own clock, which no change of the wall clock moves
            $now = hrtime(true) / 1e9;
            $idle = $run->alive() || $this->mergerAlive((string) $merge['card']) ? null : ($idle ?? $now);
            if ($idle !== null && $now - $idle >= self::IDLE_SECONDS) {
                return 0;
            }
            // due a heartbeat after the last beat that landed, or at once when the wall clock went back past it; a beat
            // that did not land leaves beat_at as it was, so the next try waits a heartbeat all the same
            $since = microtime(true) - (float) strtotime((string) ($merge['beat_at'] ?? ''));
            if (($since >= MergeLease::HEARTBEAT_SECONDS || $since < 0) && $now - $tried >= MergeLease::HEARTBEAT_SECONDS) {
                $tried = $now;
                try {
                    $this->lease->beat();
                } catch (LostClaim) {
                    return 0;
                } catch (Throwable) {
                    // a busy board lock or a slow remote costs nothing before the lease expires
                }
            }
            sleep(self::POLL_SECONDS);
        }
    }

    /**
     * The beater's lock, holding the lease it beats, or null when another holds it. Close-on-exec: a process the
     * beater's git leaves running (an ssh master) never keeps it held.
     *
     * @return resource|null
     */
    private function lock()
    {
        $lock = fopen($this->paths->ensureRuntime().'/'.self::LOCK, 'ce');
        if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
            return $lock;
        }
        $lock === false || fclose($lock);

        return null;
    }

    /** Whether a merger of $card works: a headless run here, or one bound to the card and beating. */
    public function mergerAlive(string $card): bool
    {
        foreach ($this->agents->running() as $run) {
            if (($run['card'] ?? null) === $card && ($run['type'] ?? null) === AgentRun::MERGER) {
                return true;
            }
        }
        $runtime = new Runtime($this->paths);
        $agent = $runtime->agentFor($card, AgentRun::MERGER);

        return $agent !== null && $runtime->state($agent) === 'live';
    }
}
