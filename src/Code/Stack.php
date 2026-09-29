<?php

namespace PetarSpasic\Kanban\Code;

use PetarSpasic\Kanban\Support\DotEnv;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `docker compose --project-directory <wt> -f <wt>/<compose_file> -p <project> …` for one worktree stack.
 * Compose prefers the shell environment over the worktree .env, so main's .env keys are removed from it.
 */
final class Stack
{
    /** @param  array<string, mixed>  $config  the `kanban.stack` config */
    public function __construct(
        public readonly string $worktree,
        public readonly string $project,
        private readonly array $config,
        private readonly string $main,
    ) {}

    /** Stacks are enabled when `stack.compose_file` is set and exists in the main checkout. */
    public static function enabled(array $config, string $main): bool
    {
        $file = $config['compose_file'] ?? null;

        return is_string($file) && $file !== '' && is_file($main.'/'.$file);
    }

    /**
     * Runs docker; stdout on success, null on failure or when docker is absent.
     *
     * @param  list<string>  $args
     */
    public static function docker(array $args, float $timeout = 30): ?string
    {
        $result = self::run($args, [], $timeout);

        return $result['code'] === 0 ? $result['out'] : null;
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|false>  $env
     * @return array{code: int, out: string, err: string}
     */
    public static function run(array $args, array $env = [], float $timeout = 600): array
    {
        $process = new Process(['docker', ...$args], null, $env, null, $timeout);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 127, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => $process->getExitCode() ?? 1, 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    /**
     * @param  list<string>  $args
     * @return array{code: int, out: string, err: string}
     */
    public function compose(array $args, float $timeout = 600): array
    {
        return self::run(['compose', '--project-directory', $this->worktree, '-f', $this->worktree.'/'.$this->config['compose_file'],
            '-p', $this->project, ...$args], $this->environment(), $timeout);
    }

    /** `config --format json` must name this project; otherwise compose would act on another stack. */
    public function assertProject(): void
    {
        $result = $this->compose(['config', '--format', 'json'], 60);
        if ($result['code'] !== 0) {
            throw new StackFailed("docker compose config failed for {$this->project}: ".trim($result['err'] ?: $result['out']));
        }
        $name = json_decode($result['out'], true)['name'] ?? null;
        if ($name !== $this->project) {
            throw new StackFailed("docker compose config names project '".($name ?? '?')."', expected '{$this->project}' (check COMPOSE_PROJECT_NAME and the compose `name:`)");
        }
    }

    /** @return array{code: int, out: string, err: string} */
    public function up(): array
    {
        return $this->compose(['up', '-d', '--build']);
    }

    public static function portAllocated(array $result): bool
    {
        return $result['code'] !== 0 && str_contains($result['err'].$result['out'], 'port is already allocated');
    }

    /** @return array{code: int, out: string, err: string} */
    public function down(): array
    {
        return $this->compose(['down', ...array_map('strval', (array) ($this->config['down'] ?? []))], 300);
    }

    /** Down by project name alone (the worktree and its compose file may be gone). */
    public static function downProject(string $project, array $args): array
    {
        return self::run(['compose', '-p', $project, 'down', ...array_map('strval', $args)], [], 300);
    }

    public function running(): bool
    {
        return $this->idle() === false;
    }

    /** True when compose says the stack has no containers, false when it has some, null when it cannot say (docker down, timeout). */
    public function idle(): ?bool
    {
        $result = $this->compose(['ps', '-q'], 60);

        return $result['code'] === 0 ? trim($result['out']) === '' : null;
    }

    /** @return list<string> compose project names on this machine, running or not */
    public static function projects(): array
    {
        $list = json_decode(self::docker(['compose', 'ls', '-a', '--format', 'json']) ?? '[]', true);

        return array_values(array_filter(array_map(fn ($p) => $p['Name'] ?? null, is_array($list) ? $list : [])));
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $keys = array_keys(DotEnv::parse($this->main.'/.env') + DotEnv::parse($this->worktree.'/.env')
            + array_flip(array_keys((array) ($this->config['env'] ?? []))) + array_flip(array_keys((array) ($this->config['ports'] ?? []))));
        $env = array_fill_keys($keys, false);
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'COMPOSE_')) {
                $env[$key] = false;
            }
        }

        return $env;
    }
}
