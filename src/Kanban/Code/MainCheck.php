<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** `finish.check` on main after a merge, and the runtime marker that holds the next `finish` while main is red. */
final class MainCheck
{
    /** A whole suite, so far longer than a gate's limit. */
    public const TIMEOUT = 1800;

    private const TAIL_LINES = 40;

    private const TAIL_CHARS = 3000;

    public function __construct(private readonly Paths $paths) {}

    /** @return array{sha: string, after: string, command: string, tail: string, card: ?string, at: string}|null */
    public function red(): ?array
    {
        $marker = json_decode((string) @file_get_contents($this->file()), true);

        return is_array($marker) && isset($marker['command']) ? $marker : null;
    }

    /** @param  array{sha: string, after: string, command: string, tail: string, card: ?string, at: string}  $marker */
    public function markRed(array $marker): void
    {
        $this->paths->ensureRuntime();
        $tmp = $this->file().'.'.getmypid();
        file_put_contents($tmp, json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        rename($tmp, $this->file());
    }

    public function clear(): void
    {
        @unlink($this->file());
    }

    /**
     * Runs the commands in the main checkout; the first failure as its command, exit (null: timed out) and output tail.
     *
     * @param  list<string>  $commands
     * @return array{command: string, exit: ?int, tail: string}|null
     */
    public function failure(array $commands): ?array
    {
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $this->paths->main, ['XDEBUG_MODE' => 'off'], null, self::TIMEOUT);
            try {
                $process->run();
                $code = $process->getExitCode();
            } catch (ProcessTimedOutException) {
                $code = null;
            }
            if ($code === 0) {
                continue;
            }
            $tail = trim(implode("\n", array_slice(explode("\n", rtrim($process->getOutput()."\n".$process->getErrorOutput())), -self::TAIL_LINES)));

            return ['command' => $command, 'exit' => $code, 'tail' => strlen($tail) > self::TAIL_CHARS ? '…'.substr($tail, -self::TAIL_CHARS) : $tail];
        }

        return null;
    }

    private function file(): string
    {
        return $this->paths->runtime('main-check.json');
    }
}
