<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;

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
