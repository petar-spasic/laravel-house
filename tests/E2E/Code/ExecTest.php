<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $this->id = $this->code->started('Exec here');
    $this->wt = $this->code->worktree($this->id);
    $this->container = 'acme-wt-'.basename($this->wt).'-app-1';
});

afterEach(function () {
    $this->code->killServers();
});

/** vendor/bin/kanban-exec with the fake docker. */
function kanbanExec(CodeSandbox $code, array $args, array $env = []): Process
{
    $process = new Process([$code->root().'/vendor/bin/kanban-exec', ...$args], $code->root(), $code->env($env));
    $process->run();

    return $process;
}

/** @return list<int> pids of `sleep $marker` */
function processesWith(string $marker): array
{
    $pids = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        if (@file_get_contents($file) === "sleep\0{$marker}\0") {
            $pids[] = (int) basename(dirname($file));
        }
    }

    return $pids;
}

it('runs the command in the card container at the directory, as you, with its output and exit code', function () {
    $run = kanbanExec($this->code, [$this->container, $this->wt, 'pwd; echo oops >&2; exit 6']);

    expect($run->getExitCode())->toBe(6)
        ->and($run->getOutput())->toBe($this->wt."\n")
        ->and($run->getErrorOutput())->toBe("oops\n")
        ->and(collect($this->code->calls())->first(fn ($call) => str_starts_with($call, 'exec ')))
        ->toStartWith('exec -i -u '.posix_getuid().':'.posix_getgid()." -w {$this->wt} {$this->container} bash -c ");
});

it('takes the user from -u', function () {
    kanbanExec($this->code, ['-u', '1234:5678', $this->container, $this->wt, 'true']);

    expect(collect($this->code->calls())->first(fn ($call) => str_starts_with($call, 'exec ')))->toStartWith('exec -i -u 1234:5678 -w ');
});

it('refuses anything but a running card container and a plain directory in its worktree', function (array $args, array $env, string $error) {
    $args = array_map(fn ($a) => strtr($a, ['{wt}' => $this->wt, '{root}' => $this->code->root(), '{c}' => $this->container]), $args);
    $env = array_map(fn ($v) => strtr($v, ['{root}' => $this->code->root(), '{c}' => $this->container]), $env);
    $error = strtr($error, ['{root}' => $this->code->root()]);

    $run = kanbanExec($this->code, $args, $env);

    expect($run->getExitCode())->toBe(125)
        ->and($run->getErrorOutput())->toContain($error)
        ->and(collect($this->code->calls())->contains(fn ($call) => str_starts_with($call, 'exec ')))->toBeFalse();
})->with([
    'a container that mounts the main checkout' => [['acme-local-app-1', '{root}', 'ls'], ['FAKE_DOCKER_CONTAINERS' => '{"acme-local-app-1": ["{root}"]}'], 'mounts the main checkout'],
    'a directory outside the worktree' => [['{c}', '{root}/storage', 'ls'], [], 'does not mount {root}/storage'],
    'an unknown container' => [['nope-app-1', '{wt}', 'ls'], [], 'No such container: nope-app-1'],
    'a stopped container' => [['{c}', '{wt}', 'ls'], ['FAKE_DOCKER_STOPPED' => '{c}'], 'vendor/bin/kanban stack wait starts it'],
    'root' => [['-u', '0:0', '{c}', '{wt}', 'ls'], [], 'refusing to run as root'],
    'a relative directory' => [['{c}', 'app', 'ls'], [], 'CWD must be absolute'],
    'a directory with ..' => [['{c}', '{wt}/../..', 'ls'], [], 'CWD must be a plain path'],
]);

it('ends the command in the container when its client dies', function (string $kill) {
    $marker = '307.'.random_int(100000, 999999);
    $process = proc_open(['setsid', $this->code->root().'/vendor/bin/kanban-exec', $this->container, $this->wt, "sleep {$marker} & sleep {$marker}; wait"],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $this->code->root(), $this->code->env() + getenv());
    $pid = proc_get_status($process)['pid'];
    $deadline = microtime(true) + 10;
    while (count(processesWith($marker)) < 2 && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect(processesWith($marker))->toHaveCount(2);

    posix_kill($kill === 'group' ? -$pid : $pid, $kill === 'group' ? SIGKILL : SIGTERM);
    $deadline = microtime(true) + 10;
    // proc_get_status reaps the killed client, as the Bash tool does: a zombie still answers `kill -0`.
    while (proc_get_status($process) && processesWith($marker) !== [] && microtime(true) < $deadline) {
        usleep(100_000);
    }
    $left = processesWith($marker);
    array_map(fn (int $pid) => posix_kill($pid, SIGKILL), $left);
    proc_close($process);

    expect($left)->toBe([]);
})->with([
    'SIGKILL to its process group (TaskStop)' => ['group'],
    'SIGTERM to the shell' => ['shell'],
]);

it('allows in the project settings exactly the command Guard routes an agent\'s shell through', function () {
    $code = $this->code;
    file_put_contents($code->root().'/docker-compose.local.yml', "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices:\n  app:\n    volumes: ['./:\${KANBAN_WORKTREE_PATH:-/app}']\n");
    $code->sandbox->git('commit', '-q', '-am', 'mount the worktree');
    $id = $code->started('Routed');
    $wt = $code->worktree($id);
    $code->kanban(['doctor', '--fix']);
    @mkdir($code->root().'/.git/laravel-house/agents', 0775, true);
    file_put_contents($code->root().'/.git/laravel-house/agents/w1.json', json_encode([
        'agent_id' => 'w1', 'agent_type' => 'kanban-worker', 'card' => $id, 'worktree' => '.claude/worktrees/'.basename($wt), 'stopped_at' => null,
    ]));
    $guard = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/kanban-guard'], $wt);
    $guard->setInput(json_encode(['cwd' => $wt, 'hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'php artisan test'],
        'agent_id' => 'w1', 'agent_type' => 'kanban-worker']));
    $guard->run();

    $rules = array_values(array_filter(json_decode(file_get_contents($code->root().'/.claude/settings.json'), true)['permissions']['allow'],
        fn (string $rule) => str_contains($rule, 'kanban-exec')));
    $command = json_decode($guard->getOutput(), true)['hookSpecificOutput']['updatedInput']['command'];
    expect($rules)->toHaveCount(1)
        ->and($rules[0])->toStartWith('Bash(')->toEndWith(' *)')
        ->and($command)->toStartWith(substr($rules[0], 5, -2))
        ->and($command)->toBe("{$code->root()}/vendor/bin/kanban-exec acme-wt-".basename($wt)."-app-1 '{$wt}' 'php artisan test'");
});
