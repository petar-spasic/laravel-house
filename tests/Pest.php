<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class)->in('E2E');

/** laravel-project-setup's install.php run on $repo; `--templates` and `--render-to` take paths. */
function installPhp(string $repo, array $args): Process
{
    $process = new Process(['php', Sandbox::package().'/resources/boost/skills/laravel-project-setup/scripts/install.php', $repo, ...$args]);
    $process->run();

    return $process;
}

/** An installed sandbox with the package in vendor (as composer would put it) and a compose fixture. */
function doctorSandbox(string $compose = 'compose-good.yml', string $env = "COMPOSE_PROJECT_NAME=app-local\n", ?string $name = null): Sandbox
{
    $sandbox = Sandbox::create($name ?? 'main');
    @mkdir($sandbox->root.'/vendor/petar-spasic', 0775, true);
    symlink(Sandbox::package(), $sandbox->root.'/vendor/petar-spasic/laravel-house');
    copy(__DIR__.'/E2E/Install/fixtures/'.$compose, $sandbox->root.'/docker-compose.local.yml');
    file_put_contents($sandbox->root.'/.env', $env);
    @mkdir($sandbox->root.'/config', 0775, true);
    file_put_contents($sandbox->root.'/config/kanban.php', "<?php\n\nreturn ['finish' => ['check' => ['php artisan test']]];\n");
    $sandbox->install('ACME');

    return $sandbox;
}

/** @param  array<string, string>  $env */
function doctor(Sandbox $sandbox, array $args = [], array $env = []): Process
{
    return $sandbox->kanban(['doctor', ...$args], $env + [
        'PATH' => __DIR__.'/E2E/Install/fixtures:'.getenv('PATH'),
        'KANBAN_STATE_DIR' => Sandbox::tmp(),
        'FAKE_POOLS' => '[{"Base":"198.18.0.0/16","Size":24}]',
        'FAKE_SUBNETS' => '198.18.0.0/24 198.18.1.0/24',
    ]);
}

/** What the fake claude does for a kanban agent of $type. */
function runAgent(string $claude, string $type, string $script): void
{
    file_put_contents("{$claude}/kanban-{$type}.sh", $script);
}

/** One `run --once` pass, then waits until the agents and the merge it started have ended. */
function runPass(CodeSandbox $code, string $claude, array $args = ['--once'], array $env = []): string
{
    $process = $code->kanban(['run', ...$args], [
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $claude,
    ] + $env);
    runSettled($code);

    return $process->getOutput().$process->getErrorOutput();
}

/** Waits until the agents and the detached `finish` that `kanban run` started in $code have ended. */
function runSettled(CodeSandbox $code): void
{
    $runtime = $code->root().'/.git/laravel-house';
    // a detached finish takes its lock a moment after it starts; until it has ended, its run.json has no exit beside it
    $merging = function () use ($runtime) {
        if (is_file($runtime.'/merge/run.json') && ! is_file($runtime.'/merge/exit')) {
            return true;
        }
        $lock = @fopen($runtime.'/merge.run.lock', 'c');
        if ($lock === false || flock($lock, LOCK_SH | LOCK_NB)) {
            $lock === false || fclose($lock);

            return false;
        }
        fclose($lock);

        return true;
    };
    $deadline = microtime(true) + 120;
    do {
        $live = array_filter(glob($runtime.'/runs/*.pid') ?: [],
            fn (string $f) => posix_kill((int) (json_decode((string) file_get_contents($f), true)['pid'] ?? 0), 0));
        $busy = $live !== [] || $merging();
        $busy && usleep(100_000);
    } while ($busy && microtime(true) < $deadline);
}

/** @return list<list<string>> the fake claude's argv, one per launch */
function runLaunches(string $claude): array
{
    $log = $claude.'/calls.log';

    return is_file($log) ? array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($log))))) : [];
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

/** The `sleep $marker` processes still alive after $seconds, each then killed so none outlives the test. */
function survivors(string $marker, float $seconds = 3): array
{
    for ($deadline = microtime(true) + $seconds; processesWith($marker) !== [] && microtime(true) < $deadline;) {
        usleep(100_000);
    }
    $left = processesWith($marker);
    array_map(fn (int $pid) => posix_kill($pid, SIGKILL), $left);

    return $left;
}
