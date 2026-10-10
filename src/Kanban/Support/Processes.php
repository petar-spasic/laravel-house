<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use Closure;

/** This machine's processes as /proc lists them; empty without /proc. */
final class Processes
{
    /** @return array<int, array{0: int, 1: int, 2: string}> pid → [parent pid, process group, state] */
    public static function table(): array
    {
        $table = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = @file_get_contents($file);
            // the command name may hold spaces and parentheses: the fields follow its last `)`
            if (is_string($stat) && ($at = strrpos($stat, ')')) !== false) {
                $fields = explode(' ', substr($stat, $at + 2));
                $table[(int) basename(dirname($file))] = [(int) ($fields[1] ?? 0), (int) ($fields[2] ?? 0), (string) ($fields[0] ?? '')];
            }
        }

        return $table;
    }

    /**
     * Every process under $pids now, never $pids themselves or this process.
     *
     * @param  list<int>  $pids
     * @return list<int>
     */
    public static function descendants(array $pids): array
    {
        $children = [];
        foreach (self::table() as $pid => [$parent]) {
            $children[$parent][] = $pid;
        }
        $found = [];
        for ($queue = $pids; $queue !== [];) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! isset($found[$child]) && ! in_array($child, $pids, true)) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }
        unset($found[getmypid()]);

        return array_keys($found);
    }

    /**
     * Ends $pid and every process under it: SIGTERM to each, taken from /proc before the first signal (a parent that
     * dies leaves its children to init), then SIGKILL after $grace seconds to what is left and what it started meanwhile.
     * $alive tells whether $pid still runs (a Process's isRunning(), which reaps it). By pid only: the tree shares this
     * process's group, which is never signalled. Without /proc, $pid alone.
     *
     * @param  Closure(): bool  $alive
     */
    public static function end(int $pid, Closure $alive, float $grace = 5): void
    {
        $tree = self::descendants([$pid]);
        $left = function () use (&$tree) {
            return array_values(array_filter($tree, fn (int $p) => self::running($p)));
        };
        foreach ([SIGTERM => $grace, SIGKILL => 2] as $signal => $wait) {
            if ($signal === SIGKILL) {
                // from $pid only while it runs: once reaped, its number may be another process's
                $tree = array_values(array_unique([...$tree, ...self::descendants([...($alive() ? [$pid] : []), ...$left()])]));
            }
            $alive() && posix_kill($pid, $signal);
            array_map(fn (int $p) => posix_kill($p, $signal), $left());
            for ($deadline = microtime(true) + $wait; ($alive() || $left() !== []) && microtime(true) < $deadline;) {
                usleep(50_000);
            }
            if (! $alive() && $left() === []) {
                return;
            }
        }
    }

    /** Whether $pid runs: a zombie, which no signal ends, does not. */
    private static function running(int $pid): bool
    {
        if ($pid === getmypid() || ! posix_kill($pid, 0)) {
            return false;
        }
        $stat = @file_get_contents("/proc/{$pid}/stat");

        return ! is_string($stat) || ($at = strrpos($stat, ')')) === false || substr($stat, $at + 2, 1) !== 'Z';
    }
}
