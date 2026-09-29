<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;

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

    $output = $code->ok(['stack', 'gc'], ['FAKE_DOCKER_PROJECTS' => 'other-wt-x,unrelated']);

    expect($output)->toBe(implode("\n", [
        "gc acme-wt-{$lc}: worktree gone, stack down, slot 1 released",
        'unregistered other-wt-x (remove with `kanban stack gc --force`)',
        'pruned worktrees',
    ])."\n")
        ->and(array_column($code->stacks(), 'card'))->toBe([$keep])
        ->and($code->calls())->toContain("compose -p acme-wt-{$lc} down -v --remove-orphans --rmi local")
        ->and(trim($code->sandbox->git('worktree', 'list')))->not->toContain($lc)
        ->and(trim($code->sandbox->git('worktree', 'list')))->toContain('docs/kanban')
        ->and($code->sandbox->boardGit('rev-parse', 'HEAD'))->toBe($board);

    expect($code->ok(['stack', 'gc', '--force'], ['FAKE_DOCKER_PROJECTS' => 'other-wt-x']))->toContain('removed other-wt-x');
});
