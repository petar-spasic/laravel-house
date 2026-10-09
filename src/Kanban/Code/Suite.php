<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Gates;
use Symfony\Component\Process\Process;

/**
 * `finish.check`, the whole suite, on a merged tree in the merge clone: each command where the gates run (the merge
 * stack's container), from main's config. The merge queue merges nothing while it is empty.
 */
final class Suite
{
    /** A whole suite, so far longer than a gate's limit. */
    public const TIMEOUT = 1800;

    /** @param  array<string, mixed>  $config  main's `kanban` config */
    public function __construct(private readonly array $config) {}

    /** @return list<string> */
    public function commands(): array
    {
        return array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($this->config['finish']['check'] ?? [])), fn (string $c) => $c !== ''));
    }

    /**
     * Runs $command in the clone ($dir, a directory in it, as its cwd): null when it passed, else its exit (null: timed out)
     * and output tail. $tick as Gates::wait takes it.
     *
     * @return array{command: string, exit: ?int, tail: string}|null
     */
    public function run(string $clone, string $command, ?Closure $tick = null, ?string $dir = null, int $timeout = self::TIMEOUT): ?array
    {
        $process = Process::fromShellCommandline((new Gates($this->config))->where($command, $clone, $dir), $dir ?? $clone, ['XDEBUG_MODE' => 'off'], null, $timeout);
        $code = Gates::wait($process, $tick);

        return $code === 0 ? null : ['command' => $command, 'exit' => $code, 'tail' => Gates::tail($process)];
    }
}
