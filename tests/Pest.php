<?php

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
