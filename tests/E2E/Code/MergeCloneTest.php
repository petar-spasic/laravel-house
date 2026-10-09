<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

/*
 * What a card's tree, its merger or a process left in the merge stack can make of the merge clone's working tree and
 * `.git`, and what this machine still does with it.
 */

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $this->code->configure(['gates' => ['report' => ['test "$FAKE_DOCKER_EXEC" = 1']]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'config');
    $this->code->mountWorktree();
    $this->code->defaults = ['FAKE_DOCKER_SERVE' => '1'];
    $this->origin = Origin::create();
    $this->code->sandbox->addRemote($this->origin);
    $this->outside = Sandbox::tmp().'/outside';
    mkdir($this->outside.'/cache', 0775, true);
    foreach (['Keep.php', 'cache/Keep.php', 'secret.txt'] as $file) {
        file_put_contents($this->outside.'/'.$file, "outside\n");
    }
});

/** A commit in the card's clone made with $make (given the clone), as its worker makes it. */
function cardCommit(CodeSandbox $code, string $id, Closure $make, string $message = 'work'): string
{
    $wt = $code->worktree($id);
    $make($wt);
    $code->gitIn($wt, 'add', '-A');
    $code->gitIn($wt, 'commit', '-q', '-m', $message);

    return trim($code->gitIn($wt, 'rev-parse', 'HEAD'));
}

it('clears the merged tree\'s bootstrap cache in the merge clone', function () {
    $code = $this->code;
    $first = $code->started('Adds the cache');
    cardCommit($code, $first, fn (string $wt) => @mkdir($wt.'/bootstrap/cache', 0775, true) && file_put_contents($wt.'/bootstrap/cache/.gitignore', "*\n!.gitignore\n"));
    $code->approve($first);
    $code->ok(['finish', $first]);
    file_put_contents($code->mergeClone().'/bootstrap/cache/packages.php', "<?php\n");
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $code->ok(['finish', $id]);

    expect(is_file($code->mergeClone().'/bootstrap/cache/packages.php'))->toBeFalse()
        ->and(is_file($code->mergeClone().'/bootstrap/cache/.gitignore'))->toBeTrue();
});

it('pushes only the commit it checked, never one a process in the merge stack moved its branch to', function () {
    $code = $this->code;
    // a hook in the tree runs in the merge container on each checkout there, and commits past the merge
    $code->sandbox->git('config', 'core.hooksPath', 'hooks');
    $id = $code->started('Sneaks a commit');
    cardCommit($code, $id, function (string $wt) {
        mkdir($wt.'/hooks');
        file_put_contents($wt.'/hooks/post-checkout', implode("\n", [
            '#!/bin/sh',
            '[ "$FAKE_DOCKER_EXEC" = 1 ] && [ "$(git symbolic-ref -q --short HEAD)" = merge ] || exit 0',
            '[ "$(git log -1 --format=%s)" = sneak ] || git -c user.name=x -c user.email=x@example.com commit -q --no-verify --allow-empty -m sneak',
            '',
        ]));
        chmod($wt.'/hooks/post-checkout', 0755);
    });
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->not->toBe(0)
        ->and($run->getOutput())->toContain('the merge clone holds commits no check ran on')
        ->and($this->origin->log('main'))->not->toContain('sneak')
        ->and($code->sandbox->read($id)['stage'])->toBe('review');
});
