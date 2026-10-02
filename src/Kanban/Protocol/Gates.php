<?php

namespace PetarSpasic\LaravelHouse\Kanban\Protocol;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** The `gates.report` commands a worker's branch must pass before its review report is applied. */
final class Gates
{
    public const TIMEOUT = 120;

    public const TAIL_LINES = 40;

    public const TAIL_CHARS = 3000;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly array $config) {}

    /** @return list<string> */
    public function commands(): array
    {
        $main = (string) ($this->config['main_branch'] ?? 'main');

        return array_values(array_map(fn ($c) => str_replace('{main_branch}', $main, (string) $c), (array) ($this->config['gates']['report'] ?? [])));
    }

    /** The first failing gate as a message (command, exit, output tail), or null when all pass. */
    public function failure(string $worktree): ?string
    {
        foreach ($this->commands() as $command) {
            $process = Process::fromShellCommandline($command, $worktree, ['XDEBUG_MODE' => 'off'], null, self::TIMEOUT);
            try {
                $process->run();
                $code = $process->getExitCode();
            } catch (ProcessTimedOutException) {
                $code = null;
            }
            if ($code === 0) {
                continue;
            }
            $lines = array_slice(explode("\n", rtrim($process->getOutput()."\n".$process->getErrorOutput())), -self::TAIL_LINES);
            $tail = trim(implode("\n", $lines));
            if (strlen($tail) > self::TAIL_CHARS) {
                $tail = '…'.substr($tail, -self::TAIL_CHARS);
            }

            return "Gate failed: `{$command}` (".($code === null ? 'timed out after '.self::TIMEOUT.' s' : "exit {$code}").')'.($tail === '' ? '' : ":\n{$tail}");
        }

        return null;
    }
}
