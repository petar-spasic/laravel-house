<?php

namespace PetarSpasic\LaravelHouse\Kanban\Upstream;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/** The GitHub CLI, always run from an argument list, never through a shell. */
final class Gh
{
    public function __construct(private readonly string $cwd) {}

    /** Why gh cannot file an issue now (not installed, signed out), or null. */
    public function unusable(): ?string
    {
        if ((new ExecutableFinder)->find('gh') === null) {
            return 'gh is not installed (https://cli.github.com)';
        }
        [$exit] = $this->run(['auth', 'status'], 30);

        return $exit === 0 ? null : 'gh is not signed in (`gh auth login`)';
    }

    /**
     * @param  list<string>  $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function run(array $args, float $timeout = 60): array
    {
        $process = new Process(['gh', ...$args], $this->cwd, ['GH_PROMPT_DISABLED' => '1', 'NO_COLOR' => '1'], null, $timeout);
        try {
            $process->run();
        } catch (Throwable $e) {
            return [127, '', $e->getMessage()];
        }

        return [$process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput()];
    }
}
