<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

it('stops a card without commits: stack down, slot released, worktree and branch removed', function () {
    $code = $this->code;
    $id = $code->started('Not needed');
    $lc = strtolower($id);
    $name = basename($code->worktree($id));

    $output = $code->ok(['stop', $id, '--to=backlog']);

    expect($output)->toBe(implode("\n", [
        "stack down .claude/worktrees/{$name}",
        "removed worktree .claude/worktrees/{$name}",
        "deleted branch card/{$lc}-not-needed",
        "{$id} doing→backlog",
    ])."\n")
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'backlog', 'claim' => null, 'work' => null])
        ->and(is_dir($code->worktree($id)))->toBeFalse()
        ->and($code->stacks())->toBe([])
        ->and(collect($code->calls())->last())->toEndWith("-p acme-wt-{$name} down -v --remove-orphans --rmi local -t 5");
});

it('parks a branch with commits, and keeps one on request', function () {
    $code = $this->code;
    $id = $code->started('Worth keeping');
    $code->commit($id, 'feature.txt', "wip\n");
    $branch = 'card/'.strtolower($id).'-worth-keeping';

    expect($code->ok(['stop', $id, '--to=ready']))->toContain("parked branch {$branch} (1 commit(s))")
        ->and($code->sandbox->read($id)['work'])->toBe(['parked_branch' => $branch]);

    $other = $code->started('Keep empty');
    $code->ok(['stop', $other, '--to=dropped', '--reason=not now', '--keep-branch']);
    expect($code->sandbox->read($other))->toMatchArray(['stage' => 'dropped', 'work' => ['parked_branch' => 'card/'.strtolower($other).'-keep-empty']]);
});

it('refuses a dirty worktree unless forced, and dropping without a reason', function () {
    $code = $this->code;
    $id = $code->started('Dirty');
    file_put_contents($code->worktree($id).'/scratch.txt', "x\n");

    $dirty = $code->kanban(['stop', $id, '--to=ready']);
    $reasonless = $code->kanban(['stop', $id, '--to=dropped']);

    expect($dirty->getExitCode())->toBe(3)
        ->and($dirty->getErrorOutput())->toContain('has uncommitted changes')
        ->and($reasonless->getExitCode())->toBe(3)
        ->and($reasonless->getErrorOutput())->toContain('dropping needs a reason')
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and($code->stacks())->toHaveCount(1);

    $code->ok(['stop', $id, '--to=ready', '--force']);
    expect(is_dir($code->worktree($id)))->toBeFalse()
        ->and($code->sandbox->read($id)['stage'])->toBe('ready');
});

it('keeps the slot and the worktree when the stack does not go down', function () {
    $code = $this->code;
    $id = $code->started('Stuck');

    $run = $code->kanban(['stop', $id, '--to=ready'], ['FAKE_DOCKER_FAIL' => 'down']);

    expect($run->getExitCode())->toBe(7)
        ->and($code->stacks())->toHaveCount(1)
        ->and(is_dir($code->worktree($id)))->toBeTrue()
        ->and($code->sandbox->read($id)['stage'])->toBe('doing');
});

it('refuses to stop a card another machine is working on unless forced', function () {
    $code = $this->code;
    $id = $code->started('Elsewhere');
    $file = $code->root()."/docs/kanban/work/{$id}.json";
    $card = json_decode(file_get_contents($file), true);
    $card['work']['host'] = 'alice-laptop';
    $card['claim']['by'] = 'alice@alice-laptop';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT)."\n");

    $refused = $code->kanban(['stop', $id, '--to=ready']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain('alice-laptop')->toContain('--force')
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and($code->calls())->not->toContain('compose --project-directory '.$code->worktree($id));
});

it('stops a card in review whose clone holds only untracked leftovers, and names them', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'app/Login.php', "<?php\n");
    $code->approve($id);
    file_put_contents($code->worktree($id).'/screenshot.png', "png\n");

    $out = $code->ok(['stop', $id, '--to=ready']);

    expect($out)->toContain('removed with the clone, untracked: screenshot.png')
        ->and($out)->toContain("{$id} review→planning: its plan does not cover the work on its branch")
        ->and($code->sandbox->read($id)['stage'])->toBe('planning');
});

it('starts a planner on a stack of its own and frees it on release, the planning branch deleted', function () {
    $code = $this->code;
    $id = $code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login', '--stage=planning']);
    $started = $code->ok(['start', $id]);
    $name = basename($code->worktree($id));
    $lc = strtolower($id);
    expect($started)->toContain("started planning {$id}\n")->toContain("stack acme-wt-{$name} slot ")
        ->and($code->stacks())->toHaveCount(1)
        ->and($code->sandbox->read($id)['work']['stack'])->not->toBeNull();

    file_put_contents($code->worktree($id).'/.tmp/plan.md', Sandbox::planFor([1]));
    $code->kanban(['plan', $id, '--plan-file=.tmp/plan.md'], [], $code->worktree($id))->mustRun();
    $code->kanban(['apply', $id])->mustRun();
    file_put_contents($code->worktree($id).'/scratch.txt', "a planner's notes\n");

    expect($code->ok(['stop', $id, '--to=ready']))->toBe(implode("\n", [
        "stack down .claude/worktrees/{$name}",
        "removed worktree .claude/worktrees/{$name}",
        "deleted branch card/{$lc}-add-login-page",
        "{$id} planning→ready",
    ])."\n")
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'ready', 'claim' => null, 'work' => null])
        ->and($code->stacks())->toBe([]);
});

it('refuses ready for a planning card before its plan, and keeps its stack', function () {
    $code = $this->code;
    $id = $code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login', '--stage=planning']);
    $code->ok(['start', $id]);

    $refused = $code->kanban(['stop', $id, '--to=ready']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$id} has no plan from its planner yet")
        ->and($code->stacks())->toHaveCount(1)
        ->and($code->ok(['stop', $id, '--to=backlog']))->toEndWith("{$id} planning→backlog\n")
        ->and($code->stacks())->toBe([]);
});

it('aborts the merge of a card it stops: the lease free, the merge clone at the base; never while it is pushed', function () {
    $code = $this->code;
    $code->configure(['gates' => ['report' => []]]);
    $code->sandbox->git('commit', '-q', '-am', 'no gates');
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $state = $code->mergeState();
    $file = $code->root().'/.git/laravel-house/merge.json';
    file_put_contents($file, json_encode(['phase' => 'pushing', 'merged' => str_repeat('b', 40)] + $state));

    $pushed = $code->kanban(['stop', $id, '--to=backlog']);
    file_put_contents($file, json_encode($state));
    $stopped = $code->kanban(['stop', $id, '--to=backlog']);

    expect($pushed->getExitCode())->toBe(11)
        ->and($pushed->getErrorOutput())->toContain("{$id} is being pushed to main: wait for it")
        ->and($stopped->getExitCode())->toBe(0, $stopped->getErrorOutput())
        ->and($stopped->getOutput())->toContain("merge of {$id} aborted")->toContain("{$id} review→backlog")
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and(is_file($code->mergeClone().'/RED'))->toBeFalse()
        ->and($code->sandbox->read($id)['stage'])->toBe('backlog');
});
