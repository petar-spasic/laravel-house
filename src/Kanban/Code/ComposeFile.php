<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

/**
 * A line-based reading of a compose file: enough for the keys compose files use. Which keys would make worktree
 * stacks collide, and which variables publish host ports (each one joins `stack.ports`, so every stack gets its own).
 */
final class ComposeFile
{
    /**
     * The stack config with every host port variable of main's compose file in `ports`: the listed ones keep their
     * offsets, the others take the next free offsets of the block, by name.
     *
     * @param  array<string, mixed>  $stack  the `kanban.stack` config
     * @return array<string, mixed>
     */
    public static function withHostPorts(array $stack, string $main): array
    {
        $stack['ports'] = self::derive($stack, $main);

        return $stack;
    }

    /** @return array<string, int> */
    private static function derive(array $stack, string $main): array
    {
        $ports = array_map('intval', (array) ($stack['ports'] ?? []));
        $file = $main.'/'.($stack['compose_file'] ?? '');
        if (! Stack::enabled($stack, $main)) {
            return $ports;
        }
        $block = (int) ($stack['pool']['block'] ?? 10);
        $known = self::portVariables($stack);
        $vars = [];
        self::problems((string) file_get_contents($file), null, $vars);
        $extra = array_values(array_diff(array_unique($vars), $known));
        sort($extra);
        $next = $ports === [] ? 0 : max($ports) + 1;
        foreach ($extra as $var) {
            if ($next < $block) {
                $ports[$var] = $next++;
            }
        }

        return $ports;
    }

    /**
     * Variables whose value differs per worktree and is a port: stack.ports, and stack.env entries built from one
     * (`DB_PORT` = `{DB_HOST_PORT}`).
     *
     * @param  array<string, mixed>  $stack
     * @return list<string>
     */
    public static function portVariables(array $stack): array
    {
        $ports = array_map('strval', array_keys((array) ($stack['ports'] ?? [])));
        $derived = [];
        foreach ((array) ($stack['env'] ?? []) as $key => $template) {
            foreach ($ports as $port) {
                if (str_contains((string) $template, '{'.$port.'}')) {
                    $derived[] = (string) $key;
                }
            }
        }

        return array_values(array_unique([...$ports, ...$derived]));
    }

    /**
     * Keys that pin one name or host port for every stack (so worktree stacks collide or hijack main), and a missing
     * required project name. A line-based reading of the YAML: enough for the keys compose files use.
     *
     * @param  list<string>|null  $portVars  the stack.ports variables; a host port taken from any other variable is a problem (null: not checked)
     * @param  list<string>  $hostVars  receives every variable a host port is taken from
     * @return list<string>
     */
    public static function problems(string $yaml, ?array $portVars = null, array &$hostVars = []): array
    {
        $problems = [];
        $section = null;
        $name = null;
        $levels = [];
        $item = null;
        $services = [];
        $ports = null;
        foreach (self::composeLines($yaml) as [$index, $raw]) {
            $line = rtrim(self::withoutComment($raw));
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $key = preg_match('/^\s*([A-Za-z0-9_.-]+)\s*:(.*)$/', $line, $m) ? $m[1] : null;
            $at = 'line '.($index + 1);
            if ($ports !== null && ($indent > $ports['indent'] || ($indent === $ports['indent'] && str_starts_with(ltrim($line), '-')))) {
                $entry = trim(ltrim(ltrim($line), '-'));
                [$port, $vars] = preg_match('/^([a-z_]+)\s*:(.*)$/', $entry, $long) === 1
                    ? ($long[1] === 'published' ? [$long[2], self::variables($long[2])] : ['', []])
                    : self::hostPort($entry);
                array_push($hostVars, ...array_filter($vars));
                array_push($problems, ...self::portProblems($port, $vars, $portVars, $ports['service'], $at));

                continue;
            }
            $ports = null;
            if ($indent === 0) {
                $section = $key;
                $levels = [];
                $item = null;
                if ($key === 'name') {
                    $name = trim($m[2]);
                }

                continue;
            }
            $levels[0] ??= $indent;
            if ($indent === $levels[0]) {
                $item = $key;

                continue;
            }
            $levels[1] ??= $indent;
            $depth = $indent === $levels[1] ? 1 : 2;
            if ($key === 'container_name') {
                $problems[] = "container_name on service {$item} ({$at}): one fixed name for every stack; remove it";
            }
            if ($depth !== 1 || $item === null) {
                continue;
            }
            if (in_array($section, ['volumes', 'networks'], true) && $key === 'name' && ! str_contains($m[2], '${COMPOSE_PROJECT_NAME')) {
                $problems[] = rtrim($section, 's')." {$item} has name: ({$at}): shared by every stack; remove it, or derive it from \${COMPOSE_PROJECT_NAME}";
            }
            if ($section === 'services' && in_array($key, ['build', 'image'], true)) {
                $services[$item][$key] = $at;
            }
            if ($section === 'services' && $key === 'ports') {
                $ports = ['indent' => $indent, 'service' => $item];
                $flow = trim($m[2]);
                foreach (str_starts_with($flow, '[') ? explode(',', trim($flow, '[] ')) : [] as $entry) {
                    [$port, $vars] = self::hostPort($entry);
                    array_push($hostVars, ...array_filter($vars));
                    array_push($problems, ...self::portProblems($port, $vars, $portVars, $item, $at));
                }
            }
        }
        foreach ($services as $service => $keys) {
            if (isset($keys['build'], $keys['image'])) {
                $problems[] = "image: on built service {$service} ({$keys['image']}): every stack would tag the same image; remove it";
            }
        }
        if ($name === null || ! str_contains($name, '${COMPOSE_PROJECT_NAME:?')) {
            $problems[] = 'top-level name: must be "${COMPOSE_PROJECT_NAME:?…}" so a worktree without its .env fails instead of taking over main\'s stack';
        }

        return $problems;
    }

    /**
     * Top-level `external: true` volumes whose name (`name:`, else the key) is fixed. In a file layered over the
     * local compose file such a name misses the local stack's volumes, which compose names `<project>_<volume>`.
     *
     * @return list<string>
     */
    public static function externalVolumeProblems(string $yaml): array
    {
        $volumes = [];
        $section = null;
        $level = null;
        $item = null;
        foreach (explode("\n", $yaml) as $index => $raw) {
            if (preg_match('/^( *)([A-Za-z0-9_.-]+)\s*:\s*(.*)$/', rtrim($raw), $m) !== 1) {
                continue;
            }
            $indent = strlen($m[1]);
            if ($indent === 0) {
                [$section, $level, $item] = [$m[2], null, null];

                continue;
            }
            if ($section !== 'volumes') {
                continue;
            }
            $level ??= $indent;
            $value = trim((string) preg_replace('/\s+#.*$/', '', $m[3]), " \"'");
            if ($indent === $level) {
                $item = $m[2];
                $volumes[$item] = ['line' => $index + 1, 'name' => $item, 'external' => false];
            } elseif ($m[2] === 'name') {
                $volumes[$item]['name'] = $value;
            } elseif ($m[2] === 'external') {
                $volumes[$item]['external'] = strtolower($value) === 'true';
            }
        }
        $problems = [];
        foreach ($volumes as $key => $volume) {
            if ($volume['external'] && ! str_contains($volume['name'], '$')) {
                $problems[] = "external volume {$key} has the fixed name {$volume['name']} (line {$volume['line']}): the local stack's volumes are named <project>_<volume>; use \"\${COMPOSE_PROJECT_NAME}_<volume>\" or a variable";
            }
        }

        return $problems;
    }

    /**
     * The host port of a short-syntax `ports:` entry (`8080` in `127.0.0.1:8080:80`, each variable expression as `$`)
     * and the names of the variables it is taken from; ['', []] when there is none.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function hostPort(string $entry): array
    {
        [$spec, $names] = self::collapseVariables(trim($entry, " \t\"'"));
        $spec = (string) preg_replace(['/^\[[^\]]*\]/', '#/\w+$#'], ['ip', ''], $spec);
        $parts = explode(':', $spec);
        $host = match (count($parts)) {
            2 => 0,
            3 => 1,
            default => null,
        };
        if ($host === null) {
            return ['', []];
        }
        $before = substr_count(implode(':', array_slice($parts, 0, $host)), '$');

        return [$parts[$host], array_slice($names, $before, substr_count($parts[$host], '$'))];
    }

    /**
     * Every top-level `${…}` (defaults and all) or `$NAME` in $value replaced by `$`, and the names of those variables
     * in order. A variable inside another's default is not one of them.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function collapseVariables(string $value): array
    {
        $out = '';
        $names = [];
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            if ($value[$i] !== '$') {
                $out .= $value[$i];

                continue;
            }
            if (($value[$i + 1] ?? '') === '$') {
                // `$$` is compose's escape for a literal dollar sign, not a variable.
                $out .= '_';
                $i++;

                continue;
            }
            if (($value[$i + 1] ?? '') === '{') {
                $depth = 0;
                for ($j = $i + 1; $j < $n; $j++) {
                    $depth += $value[$j] === '{' ? 1 : ($value[$j] === '}' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $names[] = preg_match('/^\w+/', substr($value, $i + 2), $m) === 1 ? $m[0] : '';
                $i = min($j, $n - 1);
            } elseif (preg_match('/^\w+/', substr($value, $i + 1), $m) === 1) {
                $names[] = $m[0];
                $i += strlen($m[0]);
            } else {
                $out .= '$';

                continue;
            }
            $out .= '$';
        }

        return [$out, $names];
    }

    /** @return list<string> the variables in a compose value that are not inside another variable's default */
    private static function variables(string $value): array
    {
        return array_values(array_filter(self::collapseVariables($value)[1]));
    }

    /**
     * A literal host port or range, or a host port taken from a variable outside stack.ports (so every stack publishes
     * the same port).
     *
     * @param  list<string>  $vars
     * @param  list<string>|null  $portVars
     * @return list<string>
     */
    private static function portProblems(string $port, array $vars, ?array $portVars, ?string $service, string $at): array
    {
        $port = trim($port, " \t\"'");
        if (preg_match('/^\d+(-\d+)?$/', $port) === 1) {
            return ["fixed host port {$port} on service {$service} ({$at}): one port for every stack; publish it from a stack.ports variable"];
        }
        $unknown = $portVars === null ? [] : array_values(array_diff(array_filter($vars), $portVars));

        return $unknown === [] ? [] : ["host port variable {$unknown[0]} on service {$service} ({$at}): no offset left for it in a stack.pool block, so every stack publishes the same port; list it in stack.ports or raise stack.pool.block"];
    }

    /** The line without a trailing ` # comment` (a `#` inside quotes is text). */
    private static function withoutComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && $i > 0 && ctype_space($line[$i - 1])) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return $line;
    }

    /**
     * The YAML as [line index, text] pairs with flow-style mappings (`key: { a: 1, b: [x] }`, `- { a: 1 }`) written out
     * as block lines that keep the original line number.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function composeLines(string $yaml): array
    {
        $out = [];
        foreach (explode("\n", $yaml) as $index => $raw) {
            self::expandFlow($index, rtrim($raw), $out);
        }

        return $out;
    }

    /** @param  list<array{0: int, 1: string}>  $out */
    private static function expandFlow(int $index, string $line, array &$out): void
    {
        $code = self::withoutComment($line);
        if (preg_match('/^(\s*)(-\s+)?(?:([A-Za-z0-9_.-]+)\s*:\s*)?\{(.*)\}\s*$/', $code, $m) !== 1 || ($m[2] === '' && $m[3] === '')) {
            $out[] = [$index, $line];

            return;
        }
        $indent = strlen($m[1]);
        if ($m[3] !== '') {
            $out[] = [$index, $m[1].$m[2].$m[3].':'];
            $indent += strlen($m[2]) + 2;
            $prefix = '';
        } else {
            $indent += strlen($m[2]);
            $prefix = $m[1].'- ';
        }
        foreach (self::splitTop($m[4]) as $n => $pair) {
            $lead = $prefix !== '' && $n === 0 ? $prefix : str_repeat(' ', $indent);
            self::expandFlow($index, $lead.trim($pair), $out);
        }
    }

    /** @return list<string> $text split on commas outside quotes, brackets and braces */
    private static function splitTop(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        foreach (str_split($text) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }

        return array_values(array_filter([...$parts, $current], fn (string $part) => trim($part) !== ''));
    }
}
