<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

it('serializes 20 parallel writers: 20 distinct ids, 20 commits, a clean tree', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');

    $processes = array_map(fn (int $i) => $sandbox->start(['new', 'project/work', "Parallel {$i}"]), range(1, 20));
    $ids = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        preg_match('/^created (\S+)/', $process->getOutput(), $m);
        $ids[] = $m[1];
    }

    expect(array_unique($ids))->toHaveCount(20)
        ->and($sandbox->boardLog())->toHaveCount(21)
        ->and(glob($sandbox->root.'/docs/kanban/project/work/ACME-*.json'))->toHaveCount(20)
        ->and(trim($sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and($sandbox->ok('validate'))->toContain('ok: 20 cards');
});

it('keeps concurrent edits of one card valid: each either commits or fails the rev check (exit 5)', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $id = $sandbox->card('Contended');

    $writers = array_map(fn (string $p) => $sandbox->start(['set', $id, "priority={$p}"]), ['high', 'low', 'urgent']);
    $codes = array_map(fn ($p) => $p->wait(), $writers);

    expect(array_diff($codes, [0, 5]))->toBe([])
        ->and($codes)->toContain(0)
        ->and(count($sandbox->boardLog()))->toBe(2 + count(array_keys($codes, 0, true)))
        ->and(trim($sandbox->boardGit('status', '--porcelain')))->toBe('')
        ->and($sandbox->ok('validate'))->toContain('ok: 1 cards');
});
