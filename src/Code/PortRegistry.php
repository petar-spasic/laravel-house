<?php

namespace PetarSpasic\Kanban\Code;

use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Json;
use PetarSpasic\Kanban\Support\Lock;

/**
 * Machine-wide port slots of worktree stacks: `<state>/stacks.json`, serialized by flock on `<state>/stacks.lock`.
 * A slot is a block of ports; an entry is keyed by the worktree realpath, so allocation is idempotent.
 */
final class PortRegistry
{
    /** @param  array<string, mixed>  $stack  the `kanban.stack` config */
    public function __construct(private readonly array $stack, private readonly ?string $dir = null) {}

    public function dir(): string
    {
        if ($this->dir !== null) {
            return $this->dir;
        }
        $state = getenv('KANBAN_STATE_DIR');
        if (is_string($state) && $state !== '') {
            return $state;
        }
        $xdg = getenv('XDG_STATE_HOME');
        $base = is_string($xdg) && $xdg !== '' ? $xdg : (getenv('HOME') ?: sys_get_temp_dir()).'/.local/state';

        return $base.'/laravel-kanban';
    }

    /** @return array<string, int> pool parameters: stored in the file once, else the config */
    public function pool(): array
    {
        return $this->load()['pool'];
    }

    /** @return list<array<string, mixed>> every registered stack, by slot */
    public function all(): array
    {
        $entries = array_values($this->load()['stacks']);
        usort($entries, fn (array $a, array $b) => $a['slot'] <=> $b['slot']);

        return $entries;
    }

    /** Why no further stack fits under `stack.max_stacks`, or null when one does. */
    public function full(): ?string
    {
        return $this->fullAt(count($this->all()));
    }

    private function fullAt(int $registered): ?string
    {
        $max = (int) ($this->stack['max_stacks'] ?? 6);

        return $registered >= $max
            ? "no stack slot: {$max} stacks registered on this machine (stack.max_stacks); finish or stop a card, or `kanban stack gc`"
            : null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $worktree): ?array
    {
        foreach ($this->load()['stacks'] as $entry) {
            if ($entry['worktree'] === $worktree) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The worktree's slot, allocating the first free one. Metadata (project, branch, card) is refreshed on reuse.
     *
     * @param  array{project: string, repo: string, branch: string|null, card: string|null}  $meta
     * @param  list<int>  $avoid  slots not to take (a retry after "port is already allocated")
     * @return array<string, mixed> the entry
     */
    public function allocate(string $worktree, array $meta, array $avoid = []): array
    {
        return $this->locked(function (array $data) use ($worktree, $meta, $avoid) {
            foreach ($data['stacks'] as $slot => $entry) {
                if ($entry['worktree'] === $worktree && ! in_array((int) $slot, $avoid, true)) {
                    $data['stacks'][$slot] = array_replace($entry, array_filter($meta, fn ($v) => $v !== null));

                    return [$data, $data['stacks'][$slot]];
                }
                if ($entry['worktree'] === $worktree) {
                    unset($data['stacks'][$slot]);
                }
            }
            if (($full = $this->fullAt(count($data['stacks']))) !== null) {
                throw new StackFailed($full, array_map(
                    fn (array $e) => "slot {$e['slot']} {$e['project']} {$e['worktree']}", array_values($data['stacks'])));
            }
            $pool = $data['pool'];
            $bound = self::dockerPorts();
            $skipped = [];
            for ($slot = $pool['first']; $slot <= $pool['last']; $slot++) {
                if (isset($data['stacks'][(string) $slot]) || in_array($slot, $avoid, true)) {
                    continue;
                }
                $busy = $this->busyPort($pool, $slot, $bound);
                if ($busy !== null) {
                    $skipped[] = "slot {$slot} skipped: port {$busy} in use";

                    continue;
                }
                $data['stacks'][(string) $slot] = [
                    'slot' => $slot,
                    'project' => $meta['project'],
                    'repo' => $meta['repo'],
                    'worktree' => $worktree,
                    'branch' => $meta['branch'],
                    'card' => $meta['card'],
                    'ports' => $this->ports($pool, $slot),
                    'created_at' => Clock::now(),
                ];

                return [$data, $data['stacks'][(string) $slot]];
            }

            throw new StackFailed("no free slot in ports {$pool['base']}+{$pool['block']}×[{$pool['first']}..{$pool['last']}]", $skipped);
        });
    }

    /** Forgets the worktree's slot. Call only after its stack is down. */
    public function release(string $worktree): ?array
    {
        return $this->locked(function (array $data) use ($worktree) {
            foreach ($data['stacks'] as $slot => $entry) {
                if ($entry['worktree'] === $worktree) {
                    unset($data['stacks'][$slot]);

                    return [$data, $entry];
                }
            }

            return [$data, null];
        });
    }

    /**
     * Refusals of the machine-resource precheck before an `up` (empty = go).
     *
     * @return list<string>
     */
    public function resourceRefusals(): array
    {
        $refusals = [];
        $minMem = (float) ($this->stack['min_mem_available_gib'] ?? 8);
        $mem = self::memAvailableGib();
        if ($minMem > 0 && $mem !== null && $mem < $minMem) {
            $refusals[] = sprintf('MemAvailable %.1f GiB < %s GiB (stack.min_mem_available_gib)', $mem, $minMem);
        }
        $minDisk = (float) ($this->stack['min_disk_free_gib'] ?? 20);
        $disk = @disk_free_space('/');
        if ($minDisk > 0 && $disk !== false && $disk / 1024 ** 3 < $minDisk) {
            $refusals[] = sprintf('root filesystem free %.1f GiB < %s GiB (stack.min_disk_free_gib)', $disk / 1024 ** 3, $minDisk);
        }
        $ratio = (float) ($this->stack['max_load_ratio'] ?? 0.75);
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $cpus = self::cpus();
        if ($ratio > 0 && $load !== null && $load[0] >= $ratio * $cpus) {
            $refusals[] = sprintf('1-minute load %.2f ≥ %s × %d CPUs (stack.max_load_ratio)', $load[0], $ratio, $cpus);
        }

        return $refusals;
    }

    /** @return array<string, int> env key => port */
    private function ports(array $pool, int $slot): array
    {
        $ports = [];
        foreach ((array) ($this->stack['ports'] ?? []) as $key => $offset) {
            $ports[$key] = $pool['base'] + $pool['block'] * $slot + (int) $offset;
        }

        return $ports;
    }

    /** First port of the slot's block that cannot be bound or is published by a container, or null. */
    private function busyPort(array $pool, int $slot, array $bound): ?int
    {
        $first = $pool['base'] + $pool['block'] * $slot;
        for ($port = $first; $port < $first + $pool['block']; $port++) {
            if (isset($bound[$port])) {
                return $port;
            }
            $socket = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);
            if ($socket === false) {
                return $port;
            }
            fclose($socket);
        }

        return null;
    }

    /** @return array<int, true> host ports in any container's PortBindings (running or not); empty without docker */
    private static function dockerPorts(): array
    {
        $ids = Stack::docker(['ps', '-aq']);
        if ($ids === null || trim($ids) === '') {
            return [];
        }
        $out = Stack::docker(['inspect', '--format', '{{json .HostConfig.PortBindings}}', ...preg_split('/\s+/', trim($ids))]) ?? '';
        $ports = [];
        foreach (explode("\n", $out) as $line) {
            foreach ((array) json_decode($line, true) as $bindings) {
                foreach ((array) $bindings as $binding) {
                    if (is_numeric($binding['HostPort'] ?? null)) {
                        $ports[(int) $binding['HostPort']] = true;
                    }
                }
            }
        }

        return $ports;
    }

    private static function memAvailableGib(): ?float
    {
        $info = @file_get_contents('/proc/meminfo');

        return is_string($info) && preg_match('/^MemAvailable:\s+(\d+) kB/m', $info, $m) ? (int) $m[1] / 1024 ** 2 : null;
    }

    private static function cpus(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        $count = is_string($info) ? preg_match_all('/^processor\s*:/m', $info) : 0;

        return max(1, (int) $count);
    }

    /**
     * Runs $mutate(data) → [data, result] under the exclusive lock and writes the file when data changed.
     *
     * @template T
     *
     * @param  callable(array): array{0: array, 1: T}  $mutate
     * @return T
     */
    private function locked(callable $mutate): mixed
    {
        $lock = Lock::exclusive($this->dir().'/stacks.lock', 20.0);
        try {
            $before = $this->load();
            [$after, $result] = $mutate($before);
            if ($after !== $before) {
                $after['stacks'] = (object) $after['stacks'];
                Json::write($this->file(), json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @return array{version: int, pool: array<string, int>, stacks: array<string, array<string, mixed>>} */
    private function load(): array
    {
        $data = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;
        $pool = array_map('intval', array_replace(['base' => 21000, 'block' => 10, 'first' => 1, 'last' => 99], (array) ($this->stack['pool'] ?? [])));

        return [
            'version' => 1,
            'pool' => is_array($data['pool'] ?? null) ? array_map('intval', $data['pool']) : $pool,
            'stacks' => is_array($data['stacks'] ?? null) ? $data['stacks'] : [],
        ];
    }

    private function file(): string
    {
        return $this->dir().'/stacks.json';
    }
}
