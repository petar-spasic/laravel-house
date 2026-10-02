<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

afterEach(function () {
    $this->code->killServers();
});

it('waits for /up from inside the worktree, starting the stack when it is not running', function () {
    $code = $this->code;
    $id = $code->started('Wait for me');
    $wt = $code->worktree($id);
    $lc = basename($code->worktree($id));
    $web = $code->base + 10;
    $code->ok(['stack', 'down'], cwd: $wt);

    $output = $code->ok(['stack', 'wait'], ['FAKE_DOCKER_SERVE' => '1'], $wt);

    expect($output)->toBe("up acme-wt-{$lc}\nready http://127.0.0.1:{$web}/up\n")
        ->and($code->ok(['stack', 'url'], cwd: $wt))->toBe('http://'.CodeSandbox::lanHost().":{$web}\n")
        ->and($code->ok(['stack', $id, 'status']))->toContain("acme-wt-{$lc}-app-1 running")
        ->and($code->ok(['stack', $id, 'logs']))->toContain('fake log line')
        ->and($code->ok(['stack', 'list']))->toBe("slot 1 acme-wt-{$lc} ports {$web}-".($web + 2)." {$wt} card {$id}\n");

    $code->ok(['stack', $id, 'down']);
});

it('exits 75 while the stack is still starting', function () {
    $code = $this->code;
    $id = $code->started('Slow');

    $run = $code->kanban(['stack', $id, 'wait']);

    expect($run->getExitCode())->toBe(75)
        ->and($run->getOutput())->toContain('not 200 yet; run `kanban stack wait` again');
});

it('downs a stack and releases its slot', function () {
    $code = $this->code;
    $id = $code->started('Down');

    expect($code->ok(['stack', $id, 'down']))->toBe('down acme-wt-'.basename($code->worktree($id))."; slot 1 released\n")
        ->and($code->stacks())->toBe([])
        ->and($code->ok(['stack', $id, 'up']))->toContain('up acme-wt-'.basename($code->worktree($id)))
        ->and($code->stacks())->toHaveCount(1);
});

it('creates a named worktree with a slot and no containers', function () {
    $code = $this->code;

    $output = $code->ok(['stack', 'scratch', 'create']);

    $wt = $code->root().'/.claude/worktrees/scratch';
    expect($output)->toStartWith("created worktree {$wt}\nworktree {$wt}\nstack acme-wt-scratch slot 1")
        ->and(trim($code->gitIn($wt, 'rev-parse', '--abbrev-ref', 'HEAD')))->toBe('worktree-scratch')
        ->and(file_get_contents($wt.'/.env'))->toContain('COMPOSE_PROJECT_NAME=acme-wt-scratch')
        ->and(collect($code->calls())->contains(fn ($c) => str_contains($c, ' up ')))->toBeFalse();
});

it('garbage-collects stacks whose worktree vanished and reports unregistered ones', function () {
    $code = $this->code;
    $id = $code->started('Vanish');
    $keep = $code->started('Stay');
    exec('rm -rf '.escapeshellarg($code->worktree($id)));
    $lc = basename($code->worktree($id));
    $board = $code->sandbox->boardGit('rev-parse', 'HEAD');

    $output = $code->ok(['stack', 'gc'], ['FAKE_DOCKER_PROJECTS' => 'acme-wt-stray,other-wt-x,unrelated']);

    expect($output)->toBe(implode("\n", [
        "gc acme-wt-{$lc}: worktree gone, stack down, slot 1 released",
        'unregistered acme-wt-stray (remove with `kanban stack gc --force`)',
        'pruned worktrees',
    ])."\n")
        ->and(array_column($code->stacks(), 'card'))->toBe([$keep])
        ->and($code->calls())->toContain("compose -p acme-wt-{$lc} down -v --remove-orphans --rmi local")
        ->and(trim($code->sandbox->git('worktree', 'list')))->not->toContain($lc)
        ->and(trim($code->sandbox->git('worktree', 'list')))->toContain('docs/kanban')
        ->and($code->sandbox->boardGit('rev-parse', 'HEAD'))->toBe($board);

    expect($code->ok(['stack', 'gc', '--force'], ['FAKE_DOCKER_PROJECTS' => 'acme-wt-stray,other-wt-x']))
        ->toContain('removed acme-wt-stray')->not->toContain('other-wt-x');
});

it("leaves another repository's stacks to that repository's gc", function () {
    $code = $this->code;
    $code->started('Mine');
    $file = $code->state.'/stacks.json';
    $data = json_decode((string) file_get_contents($file), true);
    $data['stacks']['9'] = ['slot' => 9, 'worktree' => '/nonexistent/other/.claude/worktrees/x', 'project' => 'other-wt-x',
        'repo' => '/nonexistent/other', 'ports' => [], 'card' => 'OTHER-1', 'branch' => 'card/other-1'];
    file_put_contents($file, json_encode($data));

    $output = $code->ok(['stack', 'gc']);

    expect($output)->not->toContain('other-wt-x')
        ->and(array_column($code->stacks(), 'project'))->toContain('other-wt-x')
        ->and(implode("\n", $code->calls()))->not->toContain('other-wt-x');
});

it("hides the host's port and compose variables from docker", function () {
    $code = $this->code;
    $id = $code->started('Clean environment', env: [
        'WEB_PORT' => '8011', 'DB_PORT' => '5435', 'COMPOSE_PROJECT_NAME' => 'acme-local', 'COMPOSE_FILE' => '/elsewhere.yml', 'COMPOSE_PROFILES' => 'extra',
    ]);

    $code->ok(['stack', $id, 'down'], ['WEB_PORT' => '8011', 'COMPOSE_PROJECT_NAME' => 'acme-local']);

    $seen = array_values(array_filter($code->composeEnv(), fn ($call) => str_contains($call['args'], ' up ') || str_contains($call['args'], ' down ')));
    expect($seen)->not->toBe([])
        ->and(array_merge(...array_column($seen, 'env')))->toBe([]);
});

/** The stack record `stack up` writes for Guard and `stack wait`. */
function stackRecord(CodeSandbox $code, string $wt): ?array
{
    $file = $code->root().'/.git/laravel-house/stacks/'.basename($wt).'.json';

    return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
}

it('gives a card stack its host-path mount and no ssh command, and records its container for the agents\' shells', function (array $config, string $compose, string $shell) {
    $code = $this->code;
    $code->configure($config);
    file_put_contents($code->root().'/docker-compose.local.yml', $compose);
    $code->sandbox->git('commit', '-q', '--allow-empty', '-am', 'compose');
    $id = $code->started('Record me');
    $wt = $code->worktree($id);
    $lc = basename($wt);

    expect(file_get_contents($wt.'/.env'))->toContain("\nKANBAN_WORKTREE_PATH={$wt}\nKANBAN_GIT_SSH_COMMAND=\n")
        ->and(stackRecord($code, $wt))->toMatchArray([
            'worktree' => $wt, 'project' => "acme-wt-{$lc}", 'container' => "acme-wt-{$lc}-app-1", 'shell' => $shell,
        ])
        ->and(str_contains($code->ok(['context', $id], cwd: $wt), "\nshell in container acme-wt-{$lc}-app-1; git and vendor/bin/kanban run on this machine\n"))
        ->toBe($shell === 'container');

    $code->ok(['stack', $id, 'down']);

    expect(stackRecord($code, $wt))->toBeNull();
})->with([
    'mounted' => [[], "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices:\n  app:\n    volumes: ['./:\${KANBAN_WORKTREE_PATH:-/app}']\n", 'container'],
    'not mounted' => [[], "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices: {}\n", 'host'],
    'agents.shell host' => [['agents' => ['shell' => 'host']], "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices:\n  app:\n    volumes: ['./:\${KANBAN_WORKTREE_PATH:-/app}']\n", 'host'],
]);

it('recreates a running stack on wait once its docker files changed, and only then', function () {
    $code = $this->code;
    $id = $code->started('Edit the Caddyfile');
    $wt = $code->worktree($id);
    $lc = basename($wt);
    $web = $code->base + 10;
    $code->ok(['stack', 'down'], cwd: $wt);
    $code->ok(['stack', 'wait'], ['FAKE_DOCKER_SERVE' => '1'], $wt);
    $recreates = fn () => count(array_filter($code->calls(), fn ($call) => str_contains($call, 'up -d --build --force-recreate')));

    expect($code->ok(['stack', 'wait'], cwd: $wt))->toBe("ready http://127.0.0.1:{$web}/up\n")
        ->and($recreates())->toBe(0);

    @mkdir($wt.'/docker', 0775, true);
    file_put_contents($wt.'/docker/Caddyfile.local', ":8080 {\n}\n");

    expect($code->ok(['stack', 'wait'], cwd: $wt))->toBe("reloaded acme-wt-{$lc}: docker files changed\nready http://127.0.0.1:{$web}/up\n")
        ->and($recreates())->toBe(1)
        ->and($code->ok(['stack', 'wait'], cwd: $wt))->toBe("ready http://127.0.0.1:{$web}/up\n")
        ->and($recreates())->toBe(1);
});

it('reloads its own stack from a worktree and any card stack from main', function () {
    $code = $this->code;
    $id = $code->started('Reload me');
    $other = $code->started('Not yours');
    $wt = $code->worktree($id);
    $lc = basename($wt);
    $web = $code->base + 10;

    $own = $code->kanban(['stack', 'reload'], ['FAKE_DOCKER_SERVE' => '1'], $wt);
    $foreign = $code->kanban(['stack', $other, 'reload'], cwd: $wt);
    $main = $code->kanban(['stack', 'reload', $other]);

    expect($own->getOutput())->toBe("reloaded acme-wt-{$lc}\nready http://127.0.0.1:{$web}/up\n")
        ->and($code->calls())->toContain("compose --project-directory {$wt} -f {$wt}/docker-compose.local.yml -p acme-wt-{$lc} up -d --build --force-recreate")
        ->and($foreign->getExitCode())->toBe(3)
        ->and($foreign->getErrorOutput())->toContain("from a worktree, `stack reload` acts only on that worktree's own stack")
        ->and($main->getOutput())->toStartWith('reloaded acme-wt-'.basename($code->worktree($other))."\n");
    $code->ok(['stack', $id, 'down']);
});

it('runs a command in a card stack for main, with the host environment stripped', function () {
    $code = $this->code;
    $id = $code->started('Exec for main');
    $other = $code->started('Another');
    $wt = $code->worktree($id);
    $lc = basename($wt);

    $run = $code->kanban(['stack', 'exec', $id, '--', 'sh', '-c', 'pwd; echo err >&2; exit 3'], ['WEB_PORT' => '8011', 'COMPOSE_PROJECT_NAME' => 'acme-local']);
    $foreign = $code->kanban(['stack', $other, 'exec', '--', 'true'], cwd: $wt);
    $empty = $code->kanban(['stack', $id, 'exec']);

    $exec = collect($code->composeEnv())->first(fn ($call) => str_contains($call['args'], ' exec -T '));
    expect($run->getExitCode())->toBe(3)
        ->and($run->getOutput())->toBe("{$wt}\n")
        ->and($run->getErrorOutput())->toBe("err\n")
        ->and($exec['args'])->toBe("compose --project-directory {$wt} -f {$wt}/docker-compose.local.yml -p acme-wt-{$lc} exec -T app sh -c pwd; echo err >&2; exit 3")
        ->and($exec['env'])->toBe([])
        ->and($foreign->getExitCode())->toBe(3)
        ->and($empty->getExitCode())->toBe(2)
        ->and($empty->getErrorOutput())->toContain('stack exec needs a command');
});

it('forgets the stack record of a worktree that vanished on gc', function () {
    $code = $this->code;
    $id = $code->started('Gone');
    $wt = $code->worktree($id);
    expect(stackRecord($code, $wt))->not->toBeNull();
    exec('rm -rf '.escapeshellarg($wt));

    $code->ok(['stack', 'gc']);

    expect(stackRecord($code, $wt))->toBeNull();
});

it('recreates on up when the docker files changed since the stack came up', function () {
    $code = $this->code;
    $id = $code->started('Up again');
    $wt = $code->worktree($id);
    @mkdir($wt.'/docker', 0775, true);
    file_put_contents($wt.'/docker/Caddyfile.local', ":8080 {\n}\n");

    $code->ok(['stack', $id, 'up']);
    $code->ok(['stack', $id, 'up']);

    expect(array_values(array_filter($code->calls(), fn ($call) => str_contains($call, ' up -d --build'))))
        ->toHaveCount(3)
        ->sequence(
            fn ($call) => $call->not->toContain('--force-recreate'),
            fn ($call) => $call->toEndWith('up -d --build --force-recreate'),
            fn ($call) => $call->not->toContain('--force-recreate'),
        );
});

it('prints the database commands for a card with its own stack, without a seeder the branch lacks', function () {
    $code = $this->code;
    $code->configure(['migrate' => 'php artisan migrate --force', 'finish' => ['after' => ['php artisan db:seed --class=ReferenceDataSeeder --force', 'php artisan db:seed --class=DemoSeeder --force']]]);
    $id = $code->started('Seed the demo');
    $code->commit($id, 'database/seeders/DemoSeeder.php', "<?php\n");

    expect($code->ok(['context'], cwd: $code->worktree($id)))
        ->toContain("database (run after a refresh or a change to migrations, seeders or seed data, and before e2e):\n  php artisan migrate --force\n  php artisan db:seed --class=DemoSeeder --force\n");
});
