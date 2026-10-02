<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

/** The app container sees the repo at /app: the board's .git file points at a host path that does not exist there. */
function simulateContainer(Sandbox $sandbox): void
{
    $file = $sandbox->root.'/docs/kanban/.git';
    file_put_contents($file, "gitdir: /nonexistent/host/path/.git/worktrees/kanban\n");
}

/** A PATH with php but without git. */
function pathWithoutGit(): string
{
    $dir = Sandbox::tmp();
    symlink(PHP_BINARY, $dir.'/php');

    return $dir;
}

it('commits through --git-dir/--work-tree when the worktree link points nowhere', function () {
    $s = $this->sandbox;
    simulateContainer($s);

    $id = $s->card('Written in the container');
    $s->ok(['set', $id, 'priority=high']);

    expect(array_slice($s->boardLog(), 0, 2))->toBe(["{$id} set priority [owner]", "{$id} created [owner]"])
        ->and(trim($s->git('log', '-1', '--format=%an <%ae>', 'kanban')))->toBe('Kanban UI <kanban-ui@localhost>')
        ->and($s->kanban('status')->getOutput())->toContain('journal 0')
        ->and(file_exists($s->root.'/.git/laravel-kanban/journal.jsonl'))->toBeFalse();
});

it('uses the configured UI author in the container', function () {
    $s = $this->sandbox;
    simulateContainer($s);

    $s->card('Authored', env: ['KANBAN_GIT_AUTHOR' => 'Ada <ada@example.com>']);

    expect(trim($s->git('log', '-1', '--format=%an <%ae>', 'kanban')))->toBe('Ada <ada@example.com>');
});

it('journals writes when git is unusable and commits them on the next host write or sweep', function () {
    $s = $this->sandbox;
    $noGit = ['PATH' => pathWithoutGit()];

    $first = $s->kanban(['new', 'project/work', 'Offline one'], $noGit);
    expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
        ->and($first->getOutput())->toContain('journaled: 1 write(s) not committed');
    preg_match('/created (\S+)/', $first->getOutput(), $m);
    $id = $m[1];
    $s->ok(['set', $id, 'priority=high'], $noGit);

    $journal = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($s->root.'/.git/laravel-kanban/journal.jsonl'))));
    expect($journal)->toHaveCount(2)
        ->and($journal[1])->toMatchArray(['by' => 'owner', 'message' => "{$id} set priority [owner]", 'paths' => ["project/work/{$id}.json"]])
        ->and($s->boardLog())->toBe(['Kanban: initialize'])
        ->and($s->read($id)['priority'])->toBe('high');

    expect($s->ok('sweep'))->toContain('journal: committed 2 of 2 write(s)');
    expect($s->boardLog()[0])->toBe('Owner via UI: 2 changes')
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('')
        ->and(file_exists($s->root.'/.git/laravel-kanban/journal.jsonl'))->toBeFalse();
});

it('flushes the journal before the next write', function () {
    $s = $this->sandbox;
    simulateContainer($s);
    rename($s->root.'/.git/worktrees/kanban', $s->root.'/.git/worktrees/kanban-hidden');

    $offline = $s->kanban(['new', 'project/work', 'Container without git access']);
    expect($offline->getOutput())->toContain('journaled: 1 write(s)');
    preg_match('/created (\S+)/', $offline->getOutput(), $m);

    rename($s->root.'/.git/worktrees/kanban-hidden', $s->root.'/.git/worktrees/kanban');
    $s->ok(['set', $m[1], 'priority=low']);

    expect(array_slice($s->boardLog(), 0, 2))->toBe(["{$m[1]} set priority [owner]", "Owner via UI: {$m[1]} created [owner]"]);
});
