<?php

use PetarSpasic\Kanban\Tests\Support\Origin;
use PetarSpasic\Kanban\Tests\Support\Sandbox;

/** An origin with an installed, published board, plus two attached clones. */
function published(): array
{
    $origin = Origin::create();
    $seed = Sandbox::create('seed')->addRemote($origin);
    $seed->install('ACME');
    $seed->git('commit', '-q', '-am', 'Ignore the board worktree');
    $seed->git('push', '-q', 'origin', 'main');
    expect($seed->ok('sync'))->toBe("sync: pushed 1 commit(s)\n");

    $a = $origin->clone('a');
    $b = $origin->clone('b');
    foreach ([$a, $b] as $clone) {
        expect($clone->ok('attach'))->toContain('attached origin/kanban at docs/kanban');
    }

    return [$origin, $a, $b, $seed];
}

it('attaches the board on a fresh clone', function () {
    [$origin, $a] = published();

    expect(trim($a->boardGit('rev-parse', '--abbrev-ref', '@{upstream}')))->toBe('origin/kanban')
        ->and(trim($a->git('config', 'merge.kanban.driver')))->toBe("php '{$a->root}/vendor/bin/kanban' merge-driver %O %A %B %P")
        ->and(trim($a->git('status', '--porcelain')))->toBe('')
        ->and($a->ok('attach'))->toStartWith('board attached at docs/kanban')
        ->and($a->ok('validate'))->toContain('ok: 0 cards on 2 boards');

    $stray = $origin->clone('stray');
    mkdir($stray->root.'/docs/kanban', 0775, true);
    file_put_contents($stray->root.'/docs/kanban/notes.txt', 'mine');
    expect($stray->kanban('attach')->getExitCode())->toBe(3);

    $bare = Sandbox::create('fresh');
    $none = $bare->kanban('attach');
    expect($none->getExitCode())->toBe(4)
        ->and($none->getErrorOutput())->toContain('run `php artisan kanban:install`');
});

it('converges two clones: field edits merge, logs union, a true conflict takes the newest', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared card', ['--accept=One']);
    $a->ok('sync');
    expect($b->ok('sync'))->toBe("sync: pulled 1 commit(s)\n");

    $a->ok(['set', $id, 'priority=high', 'tick=1', 'title=Title from A']);
    usleep(20000);
    $b->ok(['set', $id, 'labels=+area:pdf', 'accept+=Two', 'title=Title from B']);
    $a->ok('sync');
    expect($b->ok('sync'))->toBe("sync: pushed 1 commit(s), pulled 1\n");
    $a->ok('sync');

    $fromA = $a->read($id);
    expect($fromA)->toBe($b->read($id))
        ->and($fromA)->toMatchArray([
            'title' => 'Title from B',
            'priority' => 'high',
            'labels' => ['area:pdf'],
            'acceptance' => [['id' => 1, 'text' => 'One', 'done' => true], ['id' => 2, 'text' => 'Two', 'done' => false]],
        ])
        ->and(array_column($fromA['log'], 'event'))->toBe(['created', 'set', 'set'])
        ->and(trim($a->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'HEAD')))
        ->and($origin->log('kanban'))->toHaveCount(4)
        ->and($a->ok('validate'))->toContain('ok: 1 cards');
});

it('re-ids a card whose id was taken on origin before rebasing', function () {
    [$origin, $a, $b] = published();
    $env = ['KANBAN_ID_SEQUENCE' => 'AAAAAA'];
    $fromA = $a->card('From A', env: $env);
    $fromB = $b->card('From B', env: $env);
    $depending = $b->card('Depends on the B card', ["--depends={$fromB}"]);
    expect([$fromA, $fromB])->toBe(['ACME-AAAAAA', 'ACME-AAAAAA']);
    $a->ok('sync');

    $synced = $b->ok('sync');

    expect($synced)->toMatch('/^renamed ACME-AAAAAA → (ACME-\w{6}) \(id taken on the remote\)$/m');
    preg_match('/→ (ACME-\w{6})/', $synced, $m);
    $renamed = $m[1];
    $a->ok('sync');
    expect($a->read('ACME-AAAAAA')['title'])->toBe('From A')
        ->and($a->read($renamed))->toMatchArray(['title' => 'From B'])
        ->and(end($a->read($renamed)['log']))->toMatchArray(['event' => 'renamed', 'from' => 'ACME-AAAAAA'])
        ->and($a->read($depending)['depends_on'])->toBe([$renamed])
        ->and($a->ok('validate'))->toContain('ok: 3 cards');
});

it('claims with push-or-abort when sync is on: the loser exits 8', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Contended card');
    $a->ok('sync');
    $b->ok('sync');

    $on = ['KANBAN_SYNC' => 'on'];
    expect($a->ok(['claim', $id], $on + ['KANBAN_SESSION' => 'session-a']))->toStartWith("claimed {$id}")
        ->and($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-a"');

    $lost = $b->kanban(['claim', $id], $on + ['KANBAN_SESSION' => 'session-b']);
    expect($lost->getExitCode())->toBe(8)
        ->and($lost->getErrorOutput())->toContain("{$id} is already claimed by")
        ->and($b->read($id)['claim']['session'])->toBe('session-a')
        ->and(trim($b->boardGit('status', '--porcelain')))->toBe('');
});

it('loses a claim whose push is rejected because the card changed on origin', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Raced card');
    $a->ok('sync');
    $b->ok('sync');
    $on = ['KANBAN_SYNC' => 'on'];

    $hooks = Sandbox::tmp();
    $php = PHP_BINARY;
    $bin = Sandbox::package().'/bin/kanban';
    file_put_contents("{$hooks}/pre-push", <<<SH
        #!/bin/sh
        [ -f "{$hooks}/done" ] && exit 0
        touch "{$hooks}/done"
        cd "{$a->root}" && KANBAN_SYNC=on KANBAN_SESSION=session-a XDEBUG_MODE=off "{$php}" "{$bin}" claim {$id} >/dev/null 2>&1
        exit 0
        SH);
    chmod("{$hooks}/pre-push", 0755);
    $b->git('config', 'core.hooksPath', $hooks);

    $lost = $b->kanban(['claim', $id], $on + ['KANBAN_SESSION' => 'session-b']);

    expect($lost->getExitCode())->toBe(8, $lost->getErrorOutput())
        ->and($lost->getErrorOutput())->toContain('the claim is lost')
        ->and($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-a"')
        ->and($b->read($id)['claim']['session'])->toBe('session-a')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'origin/kanban')));
});

it('keeps claims local when sync is off', function () {
    [$origin, $a] = published();
    $id = $a->readyCard('Local claim');

    $a->ok(['claim', $id], ['KANBAN_SESSION' => 's']);

    expect($origin->log('kanban'))->toHaveCount(1)
        ->and($a->ok('status'))->toContain('2 unpushed');
});

it('pushes in the background after each write when sync is on', function () {
    [$origin, $a] = published();

    $id = $a->card('Pushed by itself', env: ['KANBAN_SYNC' => 'on']);

    $deadline = microtime(true) + 15;
    while (count($origin->log('kanban')) < 2 && microtime(true) < $deadline) {
        usleep(100000);
    }
    expect($origin->log('kanban')[0])->toBe("{$id} created [owner]");
    while (is_file($a->root.'/.git/laravel-kanban/sync.lock') && ! flock(fopen($a->root.'/.git/laravel-kanban/sync.lock', 'r'), LOCK_EX | LOCK_NB) && microtime(true) < $deadline) {
        usleep(50000);
    }
});

it('publishes the board and main, merging a moved origin/main', function () {
    [$origin, $a, $b] = published();
    $a->card('Published card');
    file_put_contents($a->root.'/app.txt', "a\n");
    $a->git('add', 'app.txt');
    $a->git('commit', '-q', '-m', 'Work from A');
    file_put_contents($b->root.'/other.txt', "b\n");
    $b->git('add', 'other.txt');
    $b->git('commit', '-q', '-m', 'Work from B');
    $b->ok('publish');

    $published = $a->ok('publish');

    expect($published)->toContain('sync: pushed 1 commit(s)')
        ->toContain('main: merged origin/main')
        ->toContain('main: pushed to origin')
        ->and($origin->log('main'))->toContain('Work from A')->toContain('Work from B')
        ->and($origin->log('kanban')[0])->toEndWith('created [owner]');
});

it('reports sync without a remote', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');

    expect($sandbox->ok('sync'))->toBe("sync: no remote configured\n");
});
