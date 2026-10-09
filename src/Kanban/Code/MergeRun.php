<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Waiting;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;

/**
 * The one `kanban finish` of this checkout, detached for `kanban run`: `merge/run.json` names it, its output goes to
 * `merge/<card|follow>.out|.err` and its exit to `merge/exit`. Whether one runs is `merge.run.lock`, which `finish` holds
 * for its whole life, whoever started it.
 */
final class MergeRun
{
    /** The lock `finish` holds while it runs. */
    public const LOCK = 'merge.run.lock';

    public function __construct(private readonly Paths $paths) {}

    /**
     * Starts `kanban $args` detached (its own session), with $env: `['finish', ID]`, `['finish', ID, '--abort']` or
     * `['finish', '--follow']`.
     *
     * @param  list<string>  $args
     * @param  array<string, string|false>  $env
     */
    public function start(array $args, array $env = []): void
    {
        $dir = $this->paths->ensureRuntime('merge');
        @unlink("{$dir}/exit");
        $card = str_starts_with($args[1], '--') ? 'follow' : $args[1];
        $command = ['sh', '-c', '"$@"; echo $? > '.escapeshellarg("{$dir}/exit"), 'sh', PHP_BINARY, $this->paths->main.'/vendor/bin/kanban', ...$args];
        $process = Process::fromShellCommandline('setsid nohup '.implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg("{$dir}/{$card}.out").' 2> '.escapeshellarg("{$dir}/{$card}.err").' < /dev/null & echo $!', $this->paths->main, $env);
        $process->mustRun();
        Runtime::writeJson("{$dir}/run.json", ['pid' => (int) trim($process->getOutput()), 'card' => $card, 'started' => Clock::now()]);
    }

    /**
     * Takes this checkout's merge lock for the life of the caller (the handle it keeps); Waiting while another `finish`
     * holds it. Close-on-exec, so neither the beater nor a command it runs holds it.
     *
     * @return resource
     */
    public function lock()
    {
        $this->paths->ensureRuntime('merge');
        $lock = fopen($this->paths->runtime(self::LOCK), 'ce');
        // a moment's shared hold by alive() is no running merge: a short retry
        for ($try = 0; $lock !== false && ! ($locked = flock($lock, LOCK_EX | LOCK_NB)) && $try < 20; $try++) {
            usleep(100_000);
        }
        if ($lock === false || ! ($locked ?? false)) {
            $pid = (int) @file_get_contents($this->paths->runtime('merge/finish.pid'));

            throw new Waiting('a merge runs here'.($pid > 0 ? " (pid {$pid})" : ''));
        }
        file_put_contents($this->paths->runtime('merge/finish.pid'), (string) getmypid());

        return $lock;
    }

    /**
     * Whether a `finish` runs in this checkout: a moment's shared hold of its lock fails, or the wrapper start() ran is
     * still writing its exit.
     */
    public function alive(): bool
    {
        if ($this->wrapper() !== null) {
            return true;
        }
        $lock = @fopen($this->paths->runtime(self::LOCK), 'ce');
        if ($lock === false) {
            return false;
        }
        try {
            if (flock($lock, LOCK_SH | LOCK_NB)) {
                flock($lock, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($lock);
        }
    }

    /**
     * The detached `finish` that ended since it was last asked: its card (or `follow`), exit and output; read once.
     *
     * @return array{card: string, exit: int, out: string, err: string}|null
     */
    public function ended(): ?array
    {
        $dir = $this->paths->runtime('merge');
        $exit = @file_get_contents("{$dir}/exit");
        $run = Runtime::readJson("{$dir}/run.json");
        if ($exit === false || $run === null || $this->alive()) {
            return null;
        }
        @unlink("{$dir}/exit");
        @unlink("{$dir}/run.json");
        $card = (string) $run['card'];

        return ['card' => $card, 'exit' => (int) trim($exit),
            'out' => (string) @file_get_contents("{$dir}/{$card}.out"), 'err' => (string) @file_get_contents("{$dir}/{$card}.err")];
    }

    /** What run.json names the last detached `finish` for: its card, or `follow`. */
    public function card(): ?string
    {
        $card = Runtime::readJson($this->paths->runtime('merge/run.json'))['card'] ?? null;

        return is_string($card) ? $card : null;
    }

    /** Ends the detached `finish` and what it started (SIGTERM to its process group, SIGKILL after $grace). */
    public function stop(int $grace = 10): void
    {
        $pid = $this->wrapper();
        if ($pid === null || ! @posix_kill(-$pid, SIGTERM)) {
            return;
        }
        for ($until = time() + $grace; time() < $until && @posix_kill(-$pid, 0);) {
            usleep(100_000);
        }
        @posix_kill(-$pid, SIGKILL);
    }

    /** The pid run.json names while it is the wrapper start() ran here (the leader of its process group), else null. */
    private function wrapper(): ?int
    {
        $pid = (int) (Runtime::readJson($this->paths->runtime('merge/run.json'))['pid'] ?? 0);
        $exit = 'echo $? > '.escapeshellarg($this->paths->runtime('merge').'/exit');

        return $pid > 0 && str_contains((string) @file_get_contents("/proc/{$pid}/cmdline"), $exit) ? $pid : null;
    }
}
