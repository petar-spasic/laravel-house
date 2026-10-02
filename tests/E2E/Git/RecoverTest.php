<?php

use PetarSpasic\Kanban\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

/** A board with one card whose worktree is stopped in the middle of a rebase (as a killed sync leaves it). */
function boardMidRebase(): array
{
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $id = $sandbox->card('Survives a kill');
    $sandbox->ok(['set', $id, 'priority=low']);

    (Process::fromShellCommandline("GIT_SEQUENCE_EDITOR=\"sed -i '1s/^pick/break/'\" git rebase -i HEAD~1", $sandbox->root.'/docs/kanban'))->run();
    expect(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('HEAD');

    return [$sandbox, $id, trim($sandbox->boardGit('rev-parse', '--absolute-git-dir'))];
}

function rebaseMarker(Sandbox $sandbox, int $pid, ?int $at = null): void
{
    $dir = $sandbox->root.'/.git/laravel-kanban';
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/rebase.marker', json_encode(['pid' => $pid, 'at' => $at ?? time()]));
}

it('reattaches a board left mid-rebase by a killed kanban sync before the next write', function () {
    [$sandbox, $id, $gitdir] = boardMidRebase();
    rebaseMarker($sandbox, 2147483646);
    touch($gitdir.'/index.lock', time() - 120);

    $write = $sandbox->kanban(['set', $id, 'priority=high']);

    expect($write->getExitCode())->toBe(0, $write->getErrorOutput())
        ->and($write->getOutput().$write->getErrorOutput())->not->toContain('journaled')
        ->and(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('kanban')
        ->and($sandbox->read($id)['priority'])->toBe('high')
        ->and($gitdir.'/index.lock')->not->toBeFile()
        ->and($sandbox->root.'/.git/laravel-kanban/rebase.marker')->not->toBeFile();
});

it('leaves a rebase the owner is resolving by hand alone and refuses the write', function () {
    [$sandbox, $id, $gitdir] = boardMidRebase();

    $write = $sandbox->kanban(['set', $id, 'priority=high']);

    expect($write->getExitCode())->toBe(5)
        ->and($write->getErrorOutput())->toContain('rebase is in progress')
        ->and($gitdir.'/rebase-merge')->toBeDirectory()
        ->and(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('HEAD');
});

it('leaves a rebase alone while the kanban process that started it is alive', function () {
    [$sandbox, $id, $gitdir] = boardMidRebase();
    rebaseMarker($sandbox, getmypid());

    $write = $sandbox->kanban(['set', $id, 'priority=high']);

    expect($write->getExitCode())->toBe(5)
        ->and($gitdir.'/rebase-merge')->toBeDirectory();
});

it('reads a rebase the owner is resolving without touching it', function () {
    [$sandbox, $id, $gitdir] = boardMidRebase();

    $show = $sandbox->kanban(['show', $id]);

    expect($show->getExitCode())->toBe(0)
        ->and($gitdir.'/rebase-merge')->toBeDirectory();
});

it('recovers a killed kanban rebase when the next command only reads', function () {
    [$sandbox, $id, $gitdir] = boardMidRebase();
    rebaseMarker($sandbox, 2147483646);

    $show = $sandbox->kanban(['show', $id]);

    expect($show->getExitCode())->toBe(0)
        ->and($gitdir.'/rebase-merge')->not->toBeDirectory()
        ->and(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('kanban');
});
