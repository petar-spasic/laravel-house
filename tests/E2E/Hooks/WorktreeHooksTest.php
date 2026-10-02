<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

it('routes worktree-create and worktree-remove through kanban hook', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');

    $create = $p->hook('worktree-create', json_encode(['session_id' => 's', 'cwd' => $p->main, 'hook_event_name' => 'WorktreeCreate', 'name' => strtolower($id)]));
    expect($create->getExitCode())->toBe(0)
        ->and($create->getOutput())->toBe($wt."\n");

    $remove = $p->hook('worktree-remove', json_encode(['session_id' => 's', 'cwd' => $p->main, 'hook_event_name' => 'WorktreeRemove', 'worktree_path' => $wt]));
    expect($remove->getExitCode())->toBe(0)
        ->and($remove->getErrorOutput())->toContain("is the worktree of {$id}")
        ->and(is_dir($wt))->toBeTrue()
        ->and(glob($p->runtime('inbox/*')))->toBe([]);

    $unknown = $p->hook('pre-compact', '{}');
    expect($unknown->getExitCode())->toBe(1)
        ->and($p->hook('subagent-stop', 'not json')->getExitCode())->toBe(1);
});
