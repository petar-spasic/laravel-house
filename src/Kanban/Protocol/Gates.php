<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The `gates.report` commands a worker's branch must pass before its review report is applied. `kanban report` runs
 * them and records the pass; the stop hook only checks that record, so a long gate never runs inside the hook.
 */
final class Gates
{
    /** Seconds a gate may run unless it or `gates.timeout` says otherwise. */
    public const TIMEOUT = 120;

    public const TAIL_LINES = 40;

    public const TAIL_CHARS = 3000;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly array $config) {}

    /** @return list<array{run: string, timeout: int, when: ?string}> */
    public function commands(): array
    {
        $main = (string) ($this->config['main_branch'] ?? 'main');
        $default = max(1, (int) ($this->config['gates']['timeout'] ?? self::TIMEOUT));
        $commands = [];
        foreach ((array) ($this->config['gates']['report'] ?? []) as $gate) {
            $run = is_array($gate) ? (string) ($gate['run'] ?? '') : (string) $gate;
            if (trim($run) !== '') {
                $commands[] = ['run' => str_replace('{main_branch}', $main, $run), 'timeout' => is_array($gate) && isset($gate['timeout']) ? max(1, (int) $gate['timeout']) : $default,
                    'when' => is_array($gate) && is_string($gate['when'] ?? null) && $gate['when'] !== '' ? $gate['when'] : null];
            }
        }

        return $commands;
    }

    /** Seconds all gates may take together. */
    public function total(): int
    {
        return array_sum(array_column($this->commands(), 'timeout'));
    }

    /** Identifies the gates as configured: a record of a pass holds only while they stay the same. */
    public function hash(): string
    {
        return substr(hash('sha256', json_encode($this->commands(), JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** What a staged report keeps of a pass at $head. */
    public function passed(string $head): array
    {
        return ['head' => $head, 'hash' => $this->hash(), 'passed_at' => Clock::now()];
    }

    /**
     * Why $record does not prove the gates pass at $head, or null when it does (or there are no gates).
     *
     * @param  mixed  $record  the staged report's `gates`
     */
    public function unproven(mixed $record, string $head): ?string
    {
        if ($this->commands() === []) {
            return null;
        }
        if (! is_array($record) || ! is_string($record['head'] ?? null)) {
            return 'the gates have not passed for this report';
        }
        if ($record['head'] !== $head) {
            return 'the gates passed at '.substr($record['head'], 0, 7).', the branch is at '.substr($head, 0, 7);
        }
        if (($record['hash'] ?? null) !== $this->hash()) {
            return "main's gates changed since they passed";
        }

        return null;
    }

    /** The first failing gate as a message (command, exit, output tail), or null when all pass. */
    public function failure(string $worktree): ?string
    {
        foreach ($this->commands() as $gate) {
            $result = $this->run($gate, $worktree);
            if (! $result['ok']) {
                return 'Gate failed: `'.$gate['run'].'` ('.$result['why'].')'.($result['tail'] === '' ? '' : ":\n{$result['tail']}");
            }
        }

        return null;
    }

    /**
     * A gate runs the card's own code, so in a card whose agents' shells run in its container it runs there too, unless
     * it is a `vendor/bin/kanban` command (the host's, like the agents' own).
     */
    private function where(string $command, string $worktree): string
    {
        $paths = Paths::discover($worktree);
        if ($paths->main !== (realpath($worktree) ?: $worktree) && ($host = Guard::hostKanban($paths->main, $command)) !== null) {
            return $host;
        }
        $record = (new Worktrees($paths, $this->config))->stackRecord($worktree);
        if (($record['shell'] ?? null) !== 'container' || ! is_string($record['container'] ?? null) || ! is_file($paths->main.'/vendor/bin/kanban-exec')) {
            return $command;
        }

        return Guard::exec($paths->main).' '.escapeshellarg($record['container']).' '.escapeshellarg(realpath($worktree) ?: $worktree).' '.escapeshellarg($command);
    }

    /**
     * Recreates the card's stack when its docker files or lockfiles changed since it came up (a merge of main brought
     * them), so no gate runs against the old image: what to print, empty when the stack was fresh.
     *
     * @return list<string>
     */
    public function freshen(string $worktree): array
    {
        $worktrees = new Worktrees(Paths::discover($worktree), $this->config);
        if (($entry = $worktrees->freshen($worktree)) === null) {
            return [];
        }
        $url = $worktrees->healthUrl($entry);

        return ["reloaded {$entry['project']}: docker files changed", $worktrees->ready($entry) ? "ready {$url}" : "starting {$url}: not 200 yet"];
    }

    /**
     * Every gate, run in order.
     *
     * @return list<array{run: string, ok: bool, why: string, tail: string}>
     */
    public function results(string $worktree): array
    {
        return array_map(fn (array $gate) => ['run' => $gate['run'], ...$this->run($gate, $worktree)], $this->commands());
    }

    /**
     * @param  array{run: string, timeout: int, when: ?string}  $gate
     * @return array{ok: bool, why: string, tail: string}
     */
    private function run(array $gate, string $worktree): array
    {
        if ($gate['when'] !== null && ! file_exists($worktree.'/'.$gate['when'])) {
            return ['ok' => true, 'why' => "skipped: no {$gate['when']}", 'tail' => ''];
        }
        $process = Process::fromShellCommandline($this->where($gate['run'], $worktree), $worktree, ['XDEBUG_MODE' => 'off'], null, $gate['timeout']);
        try {
            $process->run();
            $code = $process->getExitCode();
        } catch (ProcessTimedOutException) {
            $code = null;
        }
        if ($code === 0) {
            return ['ok' => true, 'why' => 'exit 0', 'tail' => ''];
        }
        $lines = array_slice(explode("\n", rtrim($process->getOutput()."\n".$process->getErrorOutput())), -self::TAIL_LINES);
        $tail = trim(implode("\n", $lines));
        if (strlen($tail) > self::TAIL_CHARS) {
            $tail = '…'.substr($tail, -self::TAIL_CHARS);
        }

        return ['ok' => false, 'why' => $code === null ? "timed out after {$gate['timeout']} s" : "exit {$code}", 'tail' => $tail];
    }
}
