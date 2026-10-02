<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;
use Symfony\Component\Process\Process;

it('runs a real compose stack per worktree: start, wait for /up, stop', function () {
    $code = CodeSandbox::create();
    $code->configure(['stack' => ['wait_timeout' => 60]]);
    file_put_contents($code->root().'/.env', str_replace('APP_NAME=Acme', 'APP_NAME="Kanban E2E"', file_get_contents($code->root().'/.env')));
    file_put_contents($code->root().'/docker-compose.local.yml', <<<'YAML'
        name: "${COMPOSE_PROJECT_NAME:?unset}"
        services:
          app:
            image: nginx:latest
            command: ["sh", "-c", "echo ok > /usr/share/nginx/html/up && exec nginx -g 'daemon off;'"]
            ports: ["${SIDECAR_BIND:-0.0.0.0}:${WEB_PORT:?}:80"]
        YAML);
    $code->sandbox->git('commit', '-q', '-am', 'real compose');
    $real = fn (array $env = []) => $env + ['PATH' => getenv('PATH')];
    $id = $code->sandbox->readyCard('Real stack');

    $code->ok(['start', $id], $real());
    $project = 'kanban-e2e-wt-'.basename($code->worktree($id));
    $wait = $code->kanban(['stack', $id, 'wait'], $real());
    $ps = (new Process(['docker', 'compose', 'ls', '--format', 'json']))->mustRun()->getOutput();
    $code->ok(['stop', $id, '--to=ready'], $real());

    expect($wait->getExitCode())->toBe(0)
        ->and($wait->getOutput())->toContain('ready http://127.0.0.1:'.($code->base + 10).'/up')
        ->and($ps)->toContain($project)
        ->and((new Process(['docker', 'compose', 'ls', '-a', '--format', 'json']))->mustRun()->getOutput())->not->toContain($project)
        ->and($code->stacks())->toBe([]);
})->skip(getenv('KANBAN_DOCKER_TESTS') !== '1', 'real Docker: set KANBAN_DOCKER_TESTS=1');
