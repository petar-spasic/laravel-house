<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

it('claims the card and creates its worktree, branch, .env, slot and stack', function () {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Add login page');
    $lc = strtolower($id);
    $wt = $code->worktree($id);
    $name = substr($lc, 5).'-add-login-page';
    $project = "acme-wt-{$name}";
    $base = trim($code->sandbox->git('rev-parse', 'main'));
    $web = $code->base + 10;

    $output = $code->ok(['start', $id]);

    expect($output)->toBe(implode("\n", [
        "started {$id}",
        "worktree {$wt}",
        "branch card/{$lc}-add-login-page",
        "stack {$project} slot 1 http://".CodeSandbox::lanHost().":{$web}",
        'ports WEB_PORT='.$web.' DB_HOST_PORT='.($web + 1).' REDIS_HOST_PORT='.($web + 2),
        'starting: the worker runs `vendor/bin/kanban stack wait` before using it',
        "Agent(subagent_type=\"kanban-worker\", description=\"{$id} Add login page\", isolation=\"worktree\", prompt=\"Card {$id}. Worktree {$wt}\")",
    ])."\n");

    $card = $code->sandbox->read($id);
    expect($card['stage'])->toBe('doing')
        ->and($card['claim']['by'])->toContain('@')
        ->and($card['blocked'])->toBeNull()
        ->and($card['work'])->toMatchArray([
            'branch' => "card/{$lc}-add-login-page", 'base' => $base, 'worktree' => ".claude/worktrees/{$name}", 'attempt' => 1,
            'head' => null, 'approved' => null, 'merge' => null, 'finished' => null,
            'stack' => ['project' => $project, 'slot' => 1, 'ports' => ['WEB_PORT' => $web, 'DB_HOST_PORT' => $web + 1, 'REDIS_HOST_PORT' => $web + 2],
                'url' => 'http://'.CodeSandbox::lanHost().":{$web}"],
        ])
        ->and($code->sandbox->boardLog()[0])->toStartWith($id);

    expect(trim($code->gitIn($wt, 'rev-parse', '--abbrev-ref', 'HEAD')))->toBe("card/{$lc}-add-login-page")
        ->and(trim($code->gitIn($wt, 'rev-parse', 'HEAD')))->toBe($base)
        ->and(file_get_contents($wt.'/node_modules/left-pad/index.js'))->toBe("module.exports = 1;\n")
        ->and(is_file($wt.'/vendor/bin/kanban'))->toBeTrue()
        ->and(file_exists($wt.'/storage/secret.log'))->toBeFalse()
        ->and(trim($code->sandbox->git('status', '--porcelain')))->toBe('');

    $env = file_get_contents($wt.'/.env');
    expect($env)->toContain("APP_NAME=Acme\nAPP_KEY=base64:c2VjcmV0\n")
        ->and($env)->not->toContain('acme-local')
        ->and($env)->not->toContain('WEB_PORT=8011')
        ->and($env)->toContain(implode("\n", [
            "WEB_PORT={$web}", 'DB_HOST_PORT='.($web + 1), 'REDIS_HOST_PORT='.($web + 2),
            "COMPOSE_PROJECT_NAME={$project}", 'SIDECAR_BIND=127.0.0.1', 'LOCAL_APP_URL=http://'.CodeSandbox::lanHost().":{$web}",
            "APP_URL=http://localhost:{$web}", 'DB_PORT='.($web + 1), 'REDIS_PORT='.($web + 2), "SESSION_COOKIE={$project}-session",
            'XDEBUG_MODE=off', "XDEBUG_SERVER_NAME={$project}", 'HMR_CLIENT_PORT=', 'DATABASE_SEED=auto',
        ]));

    expect($code->stacks())->toHaveCount(1)
        ->and($code->stacks()[0])->toMatchArray(['slot' => 1, 'project' => $project, 'worktree' => $wt, 'card' => $id, 'repo' => $code->root()]);

    $compose = "compose --project-directory {$wt} -f {$wt}/docker-compose.local.yml -p {$project}";
    expect($code->calls())->toContain("{$compose} config --format json")
        ->and($code->calls())->toContain("{$compose} up -d --build");
});

it('gives two started cards disjoint slots', function () {
    $code = $this->code;

    $a = $code->started('First');
    $b = $code->started('Second');

    $ports = fn (string $id) => $code->sandbox->read($id)['work']['stack'];
    expect($ports($a)['slot'])->toBe(1)
        ->and($ports($b)['slot'])->toBe(2)
        ->and(array_intersect($ports($a)['ports'], $ports($b)['ports']))->toBe([])
        ->and(array_column($code->stacks(), 'card'))->toBe([$a, $b]);
});

it('skips a slot whose ports are taken on the host or by a container', function () {
    $code = $this->code;
    $socket = stream_socket_server('tcp://0.0.0.0:'.($code->base + 10 + 7));
    expect($socket)->not->toBeFalse();

    $id = $code->started('Busy host port', ['FAKE_DOCKER_BOUND' => (string) ($code->base + 20 + 3)]);

    expect($code->sandbox->read($id)['work']['stack']['slot'])->toBe(3);
    fclose($socket);
});

it('moves to the next slot and retries once when compose reports a port already allocated', function () {
    $code = $this->code;

    $id = $code->started('Allocated', ['FAKE_DOCKER_ALLOCATED' => (string) ($code->base + 10)]);

    $wt = $code->worktree($id);
    expect($code->sandbox->read($id)['work']['stack']['slot'])->toBe(2)
        ->and(file_get_contents($wt.'/.env'))->toContain('WEB_PORT='.($code->base + 20))
        ->and(count(array_filter($code->calls(), fn ($c) => str_ends_with($c, 'up -d --build'))))->toBe(2)
        ->and(array_column($code->stacks(), 'slot'))->toBe([2]);
});

it('reuses a parked branch', function () {
    $code = $this->code;
    $id = $code->started('Park me');
    $sha = $code->commit($id, 'feature.txt', "one\n");
    $code->ok(['stop', $id, '--to=ready']);

    $output = $code->ok(['start', $id]);

    $card = $code->sandbox->read($id);
    expect($output)->toContain('(parked branch reused)')
        ->and($card['work']['attempt'])->toBe(2)
        ->and(array_key_exists('parked_branch', $card['work']))->toBeFalse()
        ->and(trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD')))->toBe($sha);
});

it('refuses before claiming', function (Closure $arrange, string $message) {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Refused');
    $arrange($code, $id);

    $run = $code->kanban(['start', $id]);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain($message)
        ->and($code->sandbox->read($id)['stage'])->not->toBe('doing')
        ->and($code->calls())->toBe([]);
})->with([
    'main checkout on another branch' => [fn (CodeSandbox $c) => $c->sandbox->git('checkout', '-q', '-b', 'other'), "on 'other', not main"],
    'worktree path exists' => [fn (CodeSandbox $c, string $id) => mkdir($c->worktree($id), 0775, true), 'already exists'],
    'card not ready' => [fn (CodeSandbox $c, string $id) => $c->ok(['move', $id, 'backlog']), 'is backlog'],
]);

it('keeps the card in doing, blocked, when the stack cannot start', function () {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Broken compose');

    $run = $code->kanban(['start', $id], ['FAKE_DOCKER_CONFIG_NAME' => 'acme-local']);

    $card = $code->sandbox->read($id);
    expect($run->getExitCode())->toBe(7)
        ->and($run->getErrorOutput())->toContain("names project 'acme-local', expected 'acme-wt-".basename($code->worktree($id))."'")
        ->and($card['stage'])->toBe('doing')
        ->and($card['blocked'])->toStartWith('start failed: docker compose config names project')
        ->and($card['work']['branch'])->toStartWith('card/')
        ->and(collect($code->calls())->contains(fn ($c) => str_contains($c, ' up ')))->toBeFalse();

    $code->ok(['stop', $id, '--to=ready']);
    expect($code->sandbox->read($id))->toMatchArray(['stage' => 'ready', 'blocked' => null, 'work' => null])
        ->and($code->stacks())->toBe([]);
});

it('refuses a stack over the machine cap and on low resources', function () {
    $code = $this->code;
    $code->configure(['stack' => ['max_stacks' => 1]]);
    $code->started('One');
    $id = $code->sandbox->readyCard('Two');

    $run = $code->kanban(['start', $id]);

    expect($run->getExitCode())->toBe(7)
        ->and($run->getErrorOutput())->toContain('no stack slot: 1 stacks registered on this machine (stack.max_stacks)');

    $code->configure(['stack' => ['min_mem_available_gib' => 1_000_000]]);
    $third = $code->sandbox->readyCard('Three');
    $low = $code->kanban(['start', $third]);
    expect($low->getExitCode())->toBe(7)
        ->and($low->getErrorOutput())->toContain('not starting a stack: MemAvailable');
});

it('refuses while another main session holds the orchestrator lease', function () {
    $code = $this->code;
    $code->started('Mine', ['KANBAN_SESSION' => 'session-a']);
    $id = $code->sandbox->readyCard('Theirs');

    $run = $code->kanban(['start', $id], ['KANBAN_SESSION' => 'session-b']);

    expect($run->getExitCode())->toBe(6)
        ->and($run->getErrorOutput())->toContain('another session holds the orchestrator lease (session-a')
        ->and($code->sandbox->read($id)['stage'])->toBe('ready');
});

it('names the worktree and stack after the card, cutting a long title at a whole word', function () {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Conditional clauses with grammatical agreement across parties');
    $name = strtolower(substr($id, 5)).'-conditional-clauses-with';

    $output = $code->ok(['start', $id]);

    expect($code->sandbox->read($id)['work']['worktree'])->toBe(".claude/worktrees/{$name}")
        ->and($output)->toContain("stack acme-wt-{$name} slot")
        ->and($output)->toContain("description=\"{$id} Conditional clauses with\"");
});
