<?php

declare(strict_types=1);

/*
 * Picks the host ports of a new project's local stack and prints them as install.php --set arguments. A port is taken
 * when something listens on it or any container publishes it (running or stopped). Ports inside the kanban worktree
 * pool are never picked: its range comes from the machine's registry, else the board's defaults. With spa the web port
 * is never 8080, and ws_port is picked only with reverb without spa.
 *
 * php ports.php [--modules=htmx,islands,spa,reverb,tenancy] [--avoid=8000,5433]
 */

$modules = [];
$avoid = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--modules=')) {
        $modules = array_values(array_filter(explode(',', substr($arg, 10))));
    } elseif (str_starts_with($arg, '--avoid=')) {
        $avoid = array_map('intval', array_filter(explode(',', substr($arg, 8))));
    } else {
        fwrite(STDERR, "ports: unknown argument {$arg}\n");
        exit(1);
    }
}

$state = getenv('KANBAN_STATE_DIR') ?: (getenv('XDG_STATE_HOME') ?: (getenv('HOME') ?: sys_get_temp_dir()).'/.local/state').'/laravel-house';
$pool = (json_decode((string) @file_get_contents($state.'/stacks.json'), true)['pool'] ?? null) ?: [];
$pool += ['base' => 21000, 'block' => 10, 'first' => 1, 'last' => 99];
$reserved = [$pool['base'], $pool['base'] + $pool['block'] * ($pool['last'] + 1) - 1];

$published = [];
$ids = trim((string) shell_exec('docker ps -aq 2>/dev/null'));
if ($ids !== '') {
    $inspect = (string) shell_exec('docker inspect --format '.escapeshellarg('{{json .HostConfig.PortBindings}}').' '.implode(' ', array_map('escapeshellarg', preg_split('/\s+/', $ids))).' 2>/dev/null');
    foreach (explode("\n", $inspect) as $line) {
        foreach ((array) json_decode($line, true) as $bindings) {
            foreach ((array) $bindings as $binding) {
                is_numeric($binding['HostPort'] ?? null) && $published[(int) $binding['HostPort']] = true;
            }
        }
    }
}

$taken = array_fill_keys($avoid, true);
$pick = function (int $port) use (&$taken, $published, $reserved): int {
    for (; $port < 65536; $port++) {
        if (isset($taken[$port]) || isset($published[$port]) || ($port >= $reserved[0] && $port <= $reserved[1])) {
            continue;
        }
        $socket = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);
        if ($socket === false) {
            continue;
        }
        fclose($socket);
        $taken[$port] = true;

        return $port;
    }
    fwrite(STDERR, "ports: no free port\n");
    exit(1);
};

$spa = in_array('spa', $modules, true);
$spa && $taken[8080] = true;
$web = $pick(8000);
$sets = ['web_port' => $web, 'db_port' => $pick(5433), 'redis_port' => $pick(6380)];
if (in_array('reverb', $modules, true) && ! $spa) {
    $sets['ws_port'] = $pick($web + 1);
}

echo implode(' ', array_map(fn ($key, $port) => "--set {$key}={$port}", array_keys($sets), $sets))."\n";
