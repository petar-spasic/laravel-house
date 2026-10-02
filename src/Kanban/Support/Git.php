<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use Symfony\Component\Process\Process;
use Throwable;

final class Git
{
    /** Hook environments export these; they would point our git calls at another repository. */
    private const CLEAN_ENV = [
        'GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C',
        'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false, 'GIT_COMMON_DIR' => false, 'GIT_PREFIX' => false,
    ];

    /**
     * @param  list<string>  $global  arguments placed before the subcommand (--git-dir=…, -c k=v)
     * @param  array<string, string>  $env
     */
    public function __construct(
        public readonly string $cwd,
        private readonly array $global = [],
        private readonly array $env = [],
    ) {}

    /** @param  array<string, string>  $env */
    public function withEnv(array $env): self
    {
        return new self($this->cwd, $this->global, array_merge($this->env, $env));
    }

    /** @param  list<string>  $args */
    public function run(array $args, ?string $input = null, float $timeout = 120): string
    {
        $result = $this->attempt($args, $input, $timeout);
        if (! $result->ok()) {
            throw new GitFailed('git '.implode(' ', $args).': '.trim($result->err ?: $result->out), $result);
        }

        return $result->out;
    }

    /** @param  list<string>  $args */
    public function attempt(array $args, ?string $input = null, float $timeout = 120): GitResult
    {
        $process = new Process(['git', ...$this->global, ...$args], $this->cwd, [...self::CLEAN_ENV, ...$this->env], $input, $timeout);
        try {
            $process->run();
        } catch (Throwable $e) {
            return new GitResult(127, '', $e->getMessage());
        }

        return new GitResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /** @param  list<string>  $args */
    public function line(array $args): ?string
    {
        $result = $this->attempt($args);

        return $result->ok() ? trim($result->out) : null;
    }
}
