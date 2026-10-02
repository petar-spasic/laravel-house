<?php

use Symfony\Component\Process\Process;

function kanbanHookRepo(): array
{
    $root = realpath(sys_get_temp_dir()).'/kanban-hooks-'.bin2hex(random_bytes(6));
    mkdir($root.'/app', 0777, true);
    $hooks = dirname(__DIR__, 3).'/githooks';

    $git = function (string $args, string $cwd): Process {
        $process = Process::fromShellCommandline('git '.$args, $cwd);
        $process->run();

        return $process;
    };

    $main = $root.'/app';
    foreach (['init -q -b main', 'config user.email t@example.com', 'config user.name T', 'config commit.gpgsign false', "config core.hooksPath {$hooks}"] as $args) {
        $git($args, $main);
    }
    file_put_contents($main.'/a.txt', "a\n");
    $git('add a.txt', $main);
    $git('commit -q -m init', $main);
    $git('init -q --bare '.$root.'/origin.git', $root);
    $git('remote add origin '.$root.'/origin.git', $main);
    $git('worktree add -q -b topic .claude/worktrees/topic', $main);

    return [$main, $main.'/.claude/worktrees/topic', $git, $root];
}

it('rejects Co-Authored-By trailers in any case, in main and in a worktree', function () {
    [$main, $worktree, $git, $root] = kanbanHookRepo();

    foreach ([$main, $worktree] as $k => $dir) {
        file_put_contents($dir."/f{$k}.txt", "x\n");
        $git("add f{$k}.txt", $dir);

        foreach (['Co-authored-by: A <a@b.c>', 'CO-AUTHORED-BY: A <a@b.c>', 'co-Authored-By: Claude <noreply@anthropic.com>'] as $trailer) {
            $commit = $git('commit -q -m '.escapeshellarg("ACME-1: x\n\n{$trailer}"), $dir);
            expect($commit->getExitCode())->not->toBe(0)
                ->and($commit->getErrorOutput())->toContain('Co-Authored-By');
        }

        expect($git('commit -q -m '.escapeshellarg("ACME-1: x\n\nMentions co-authored-by: inline only"), $dir)->getExitCode())->toBe(0);
    }

    (new Process(['rm', '-rf', $root]))->run();
});

it('blocks pushes from a linked worktree and allows them from main', function () {
    [$main, $worktree, $git, $root] = kanbanHookRepo();

    $fromWorktree = $git('push -q origin topic', $worktree);
    expect($fromWorktree->getExitCode())->not->toBe(0)
        ->and($fromWorktree->getErrorOutput())->toContain("pushes are the main session's (kanban publish)");

    expect($git('push -q origin main topic', $main)->getExitCode())->toBe(0)
        ->and($git('ls-remote --heads origin', $main)->getOutput())->toContain('refs/heads/topic');

    (new Process(['rm', '-rf', $root]))->run();
});

it('ships both hooks executable', function () {
    $hooks = dirname(__DIR__, 3).'/githooks';

    expect(is_executable($hooks.'/commit-msg'))->toBeTrue()
        ->and(is_executable($hooks.'/pre-push'))->toBeTrue();
});

it("puts the card's id in front of a commit message on a card branch, once, and leaves merges and other branches alone", function () {
    [$main, $worktree, $git, $root] = kanbanHookRepo();
    $git('checkout -q -b card/acme-e58pby-add-login-page', $worktree);
    $subject = fn (string $dir) => trim($git('log -1 --format=%s', $dir)->getOutput());

    file_put_contents($worktree.'/b.txt', "b\n");
    $git('add b.txt', $worktree);
    $git('commit -q -m "login form"', $worktree);
    $plain = $subject($worktree);
    file_put_contents($worktree.'/c.txt', "c\n");
    $git('add c.txt', $worktree);
    $git('commit -q -m "ACME-E58PBY: already named"', $worktree);
    $named = $subject($worktree);
    file_put_contents($main.'/m.txt', "m\n");
    $git('add m.txt', $main);
    $git('commit -q -m "main work"', $main);
    $git('merge -q --no-edit main', $worktree);

    expect($plain)->toBe('ACME-E58PBY: login form')
        ->and($named)->toBe('ACME-E58PBY: already named')
        ->and($subject($main))->toBe('main work')
        ->and($subject($worktree))->toStartWith('Merge branch');

    (new Process(['rm', '-rf', $root]))->run();
});
