<?php

use PetarSpasic\Kanban\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

it('reattaches a board left mid-rebase by a killed sync before the next write', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $id = $sandbox->card('Survives a kill');
    $sandbox->ok(['set', $id, 'priority=low']);

    $stop = Process::fromShellCommandline("GIT_SEQUENCE_EDITOR=\"sed -i '1s/^pick/break/'\" git rebase -i HEAD~1", $sandbox->root.'/docs/kanban');
    $stop->run();
    expect(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('HEAD');
    $lock = trim($sandbox->boardGit('rev-parse', '--absolute-git-dir')).'/index.lock';
    touch($lock, time() - 120);

    $write = $sandbox->kanban(['set', $id, 'priority=high']);

    expect($write->getExitCode())->toBe(0)
        ->and($write->getOutput().$write->getErrorOutput())->not->toContain('journaled')
        ->and(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('kanban')
        ->and($sandbox->read($id)['priority'])->toBe('high')
        ->and($lock)->not->toBeFile();
});
