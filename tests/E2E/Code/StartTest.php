<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

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

it('refuses a card over the machine stack cap before claiming it, and a stack on low resources', function () {
    $code = $this->code;
    $code->configure(['stack' => ['max_stacks' => 1]]);
    $code->started('One');
    $id = $code->sandbox->readyCard('Two');

    $run = $code->kanban(['start', $id]);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain("refused {$id}: no stack slot: 1 stacks registered on this machine (stack.max_stacks)")
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'ready', 'claim' => null, 'work' => null]);

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

it('runs the main checkout\'s vendor/bin/kanban when called from a code worktree', function () {
    $code = $this->code;
    $id = $code->started('Delegate');
    $wt = $code->worktree($id);
    file_put_contents($code->sandbox->root.'/vendor/bin/kanban', "#!/usr/bin/env php\n<?php echo 'main copy: '.implode(' ', array_slice(\$argv, 1)).\"\\n\"; exit(3);\n");

    $run = new Process([PHP_BINARY, 'vendor/bin/kanban', 'context', '--evaluate'], $wt);
    $run->run();

    expect($run->getOutput())->toBe("main copy: context --evaluate\n")
        ->and($run->getExitCode())->toBe(3);
});

it('prints the spawn line again in show and refresh: the worker in doing, an evaluator in review', function () {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Spawn me again');
    $started = $code->ok(['start', $id]);
    preg_match('/^(Agent\(.+\))$/m', $started, $line);
    $path = $code->worktree($id);

    expect($line[1])->toBe("Agent(subagent_type=\"kanban-worker\", description=\"{$id} Spawn me again\", isolation=\"worktree\", prompt=\"Card {$id}. Worktree {$path}\")")
        ->and($code->ok(['show', $id]))->toContain("spawn: {$line[1]}\n");

    $code->approve($id);
    $evaluator = "spawn: Agent(subagent_type=\"kanban-evaluator\", description=\"{$id} review Spawn me again\", isolation=\"worktree\", prompt=\"Card {$id}. Worktree {$path}\")\n";
    expect($code->ok(['show', $id]))->toContain($evaluator)
        ->and($code->ok(['refresh', $id]))->toBe("up to date {$id}\n{$evaluator}");
});

it('resumes a start whose worktree went missing, and the brief points at it', function () {
    $code = $this->code;
    $id = $code->started('Lost worktree');
    $before = $code->sandbox->read($id)['work'];
    exec('rm -rf '.escapeshellarg($code->worktree($id)));

    expect($code->ok(['status']))->toContain("worktree missing (`kanban start {$id}` resumes the start)");

    $again = $code->ok(['start', $id]);
    $work = $code->sandbox->read($id)['work'];
    expect($again)->toContain("resumed {$id}\n")
        ->and(is_dir($code->worktree($id).'/.git') || is_file($code->worktree($id).'/.git'))->toBeTrue()
        ->and($work)->toMatchArray(['branch' => $before['branch'], 'worktree' => $before['worktree'], 'attempt' => 1, 'started' => $before['started']])
        ->and($work['stack'])->not->toBeNull()
        ->and($code->ok(['status']))->not->toContain('worktree missing');
});

it('records where the work goes with the claim, so a start killed before its stack is up is finished by running it again', function () {
    $code = $this->code;
    $id = $code->sandbox->readyCard('Killed start');
    $start = $code->sandbox->start(['start', $id], $code->env(['FAKE_DOCKER_DELAY' => '30']));
    $deadline = microtime(true) + 60;
    while (! is_file($code->worktree($id).'/.env') && microtime(true) < $deadline) {
        usleep(100000);
    }
    $start->signal(SIGKILL);
    $start->wait();

    $card = $code->sandbox->read($id);
    expect($card['stage'])->toBe('doing')
        ->and($card['work'])->toMatchArray(['worktree' => '.claude/worktrees/'.basename($code->worktree($id)), 'stack' => null])
        ->and($card['blocked'])->toBeNull();

    $again = $code->kanban(['start', $id]);
    expect($again->getExitCode())->toBe(0)
        ->and($again->getOutput())->toContain("resumed {$id}\n")
        ->and($code->sandbox->read($id)['work']['stack'])->not->toBeNull();
});

it("makes the card's worktree a clone of main that names main, carries main's identity and owns its own .git", function () {
    $code = $this->code;
    $code->sandbox->git('config', 'user.name', 'Ana Acme');
    $id = $code->started('Own repository');
    $wt = $code->worktree($id);
    $branch = $code->sandbox->read($id)['work']['branch'];

    expect(is_dir($wt.'/.git'))->toBeTrue()
        ->and(trim($code->gitIn($wt, 'config', 'kanban.main')))->toBe($code->root())
        ->and(trim($code->gitIn($wt, 'config', 'user.name')))->toBe('Ana Acme')
        ->and(trim($code->gitIn($wt, 'remote', 'get-url', 'origin')))->toBe($code->root())
        ->and(trim($code->gitIn($wt, 'symbolic-ref', '--short', 'HEAD')))->toBe($branch)
        ->and(trim($code->sandbox->git('worktree', 'list')))->not->toContain($wt)
        // a commit in the clone is not in main until a kanban command levels them
        ->and($code->sandbox->git('branch', '--list', $branch))->toBe('');

    $head = $code->commit($id, 'app/Own.php', "<?php\n");
    $code->ok(['context'], cwd: $wt);

    expect(trim($code->sandbox->git('rev-parse', 'refs/heads/'.$branch)))->toBe($head);
});

it("never runs what a card clone's config or attributes name, from a kanban command on this machine", function () {
    $code = $this->code;
    $id = $code->started('Hostile config');
    $wt = $code->worktree($id);
    $marker = $code->root().'/../pwned';
    $code->commit($id, 'app/A.php', "<?php\n");
    foreach (['core.fsmonitor' => "touch {$marker}-fsmonitor", 'core.hooksPath' => $wt.'/hooks', 'filter.x.clean' => "touch {$marker}-filter",
        'diff.x.textconv' => "touch {$marker}-textconv", 'core.pager' => "touch {$marker}-pager"] as $key => $value) {
        $code->gitIn($wt, 'config', $key, $value);
    }
    mkdir($wt.'/hooks');
    foreach (['pre-commit', 'commit-msg', 'post-merge', 'reference-transaction'] as $hook) {
        file_put_contents("{$wt}/hooks/{$hook}", "#!/bin/sh\ntouch {$marker}-hook-{$hook}\n");
        chmod("{$wt}/hooks/{$hook}", 0755);
    }
    file_put_contents($wt.'/.gitattributes', "* filter=x diff=x\n");
    file_put_contents($wt.'/app/A.php', "<?php // changed\n");
    $code->commitMain('main.txt', "main\n");

    $code->kanban(['context'], cwd: $wt);
    $code->kanban(['report', $id, '--status=review', '--summary=x'], cwd: $wt);
    $code->kanban(['refresh', $id]);

    expect(glob($marker.'-*') ?: [])->toBe([]);
});

it("tells an agent to run vendor/bin/kanban on its own when its card's container runs it", function () {
    // a card clone as its container sees it: kanban.main names a main checkout that is not mounted there
    $clone = Sandbox::tmp().'/card';
    mkdir($clone);
    (new Process(['git', 'init', '-q'], $clone))->mustRun();
    (new Process(['git', 'config', 'kanban.main', '/nonexistent/main'], $clone))->mustRun();

    $run = new Process([PHP_BINARY, Sandbox::package().'/bin/kanban', 'status'], $clone);
    $run->run();

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain('run it as a command of its own');
});
