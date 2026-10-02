<?php

use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

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

it('attaches from the clone\'s own copy of origin/kanban when origin cannot be reached, and never tells a teammate to start a second board', function () {
    [$origin] = published();
    $c = $origin->clone('c');
    rename($origin->path, $origin->path.'.away');

    $offline = $c->kanban('attach');
    expect($offline->getExitCode())->toBe(0, $offline->getOutput().$offline->getErrorOutput())
        ->and($offline->getOutput())->toContain('attached origin/kanban at docs/kanban');

    $d = Origin::create()->clone('d');
    $d->git('remote', 'set-url', 'origin', $origin->path);
    $d->git('update-ref', '-d', 'refs/remotes/origin/kanban');
    $blind = $d->kanban('attach');
    expect($blind->getExitCode())->not->toBe(0)
        ->and($blind->getOutput().$blind->getErrorOutput())->toContain('cannot reach')->not->toContain('kanban:install');
});

it('gives a third clone the same bytes, displaced text included', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared card');
    $a->ok('sync');
    $b->ok('sync');
    $a->ok(['set', $id, 'body=From A']);
    usleep(20000);
    $b->ok(['set', $id, 'body=From B']);
    $a->ok('sync');
    $b->ok('sync');
    $a->ok('sync');

    $c = $origin->clone('c');
    $c->ok('attach');

    expect($c->read($id))->toBe($a->read($id))->and($b->read($id))->toBe($a->read($id))
        ->and($a->read($id)['body'])->toBe('From B')
        ->and(array_column(array_filter($a->read($id)['log'], fn ($e) => $e['event'] === 'conflict'), 'lost'))->toBe(['From A'])
        ->and($c->ok('validate'))->toContain('ok: 1 cards')
        ->and($c->ok(['show', $id]))->toContain('merge kept the other version of body; replaced: From A');
});

it('shows a teammate\'s name on what they did, and stays valid on a clone that only knows the roles', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Ana starts this');
    $a->ok(['set', $id, 'note=From Ana'], ['KANBAN_USER' => 'Ana']);
    $a->ok('sync');
    $b->ok('sync');

    expect($b->ok('validate'))->toContain('ok: 1 cards')
        ->and($b->ok(['show', $id, '--log=5']))->toContain(' owner (Ana) note: From Ana');
});

it('merges with the running package when the configured merge driver cannot run on this machine', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared card', ['--accept=One']);
    $a->ok('sync');
    $b->ok('sync');

    $a->ok(['set', $id, 'priority=high', 'title=Title from A']);
    usleep(20000);
    $b->ok(['set', $id, 'labels=+area:pdf', 'title=Title from B']);
    $a->ok('sync');
    // a container sees the host's absolute path in .git/config and cannot run it
    $b->git('config', 'merge.kanban.driver', '/nonexistent/php /nonexistent/kanban merge-driver %O %A %B %P');

    $synced = $b->kanban('sync');

    expect($synced->getExitCode())->toBe(0, $synced->getErrorOutput())
        ->and($b->read($id))->toMatchArray(['title' => 'Title from B', 'priority' => 'high', 'labels' => ['area:pdf']])
        ->and($origin->log('kanban')[0])->not->toBe('')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'origin/kanban')));
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
        ->and(collect($fromA['log'])->pluck('event')->sort()->values()->all())->toBe(['conflict', 'created', 'set', 'set'])
        ->and(array_values(array_filter($fromA['log'], fn ($e) => $e['event'] === 'conflict'))[0])->toMatchArray(['field' => 'title', 'lost' => 'Title from A', 'by' => 'hook'])
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
        ->and($lost->getErrorOutput())->toContain('is already claimed by')
        ->and($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-a"')
        ->and($b->read($id)['claim']['session'])->toBe('session-a')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'origin/kanban')));
});

/** A pre-push hook for $clone that runs $script (a shell snippet) once, before the first push goes out. */
function racingPush(Sandbox $clone, string $script, bool $every = false): string
{
    $hooks = Sandbox::tmp();
    $once = $every ? '' : "[ -f \"{$hooks}/done\" ] && exit 0\ntouch \"{$hooks}/done\"\n";
    file_put_contents("{$hooks}/pre-push", "#!/bin/sh\n{$once}{$script}\nexit 0\n");
    chmod("{$hooks}/pre-push", 0755);
    $clone->git('config', 'core.hooksPath', $hooks);

    return $hooks;
}

it('keeps a claim whose push is rejected only because somebody pushed something else', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Raced card');
    $a->ok('sync');
    $b->ok('sync');
    $b->ok(['set', $id, 'body=Edited on B, not pushed yet']);
    $php = PHP_BINARY;
    $bin = Sandbox::package().'/bin/kanban';
    racingPush($b, "cd \"{$a->root}\" && export KANBAN_SYNC=off XDEBUG_MODE=off && \"{$php}\" \"{$bin}\" new project/work 'Pushed meanwhile' >/dev/null 2>&1 && \"{$php}\" \"{$bin}\" sync >/dev/null 2>&1");

    $claim = $b->kanban(['claim', $id], ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session-b']);

    expect($claim->getExitCode())->toBe(0, $claim->getErrorOutput())
        ->and($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-b"')->toContain('Edited on B, not pushed yet')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'origin/kanban')));
});

it('checks the area again after a lost push race, so two schedulers cannot both fill it', function () {
    [$origin, $a, $b] = published();
    $second = $a->readyCard('Second in the area', ['--label=area:billing']);
    $a->ok('sync');
    $b->ok('sync');
    // only A knows this one, until its claim lands during B's push
    $first = $a->readyCard('First in the area', ['--label=area:billing', '--priority=high']);
    $php = PHP_BINARY;
    $bin = Sandbox::package().'/bin/kanban';
    racingPush($b, "cd \"{$a->root}\" && KANBAN_SYNC=on KANBAN_SESSION=session-a XDEBUG_MODE=off \"{$php}\" \"{$bin}\" claim {$first} >/dev/null 2>&1");

    $lost = $b->kanban(['claim', $second], ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session-b']);

    expect($lost->getExitCode())->toBe(3, $lost->getErrorOutput())
        ->and($lost->getErrorOutput())->toContain('refused')
        ->and($origin->show("kanban:project/work/{$first}.json"))->toContain('"stage": "doing"')
        ->and($origin->show("kanban:project/work/{$second}.json"))->toContain('"stage": "ready"')
        ->and($b->read($second)['claim'])->toBeNull()
        ->and(trim($b->boardGit('rev-list', '--count', 'origin/kanban..HEAD')))->toBe('0');
});

it('gives up a claim after three rejected pushes and leaves no claim commit behind', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Never wins');
    $a->ok('sync');
    $b->ok('sync');
    $php = PHP_BINARY;
    $bin = Sandbox::package().'/bin/kanban';
    racingPush($b, "cd \"{$a->root}\" && export KANBAN_SYNC=off XDEBUG_MODE=off && \"{$php}\" \"{$bin}\" new project/work \"Pushed meanwhile \$(date +%s%N)\" >/dev/null 2>&1 && \"{$php}\" \"{$bin}\" sync >/dev/null 2>&1", every: true);

    $gave = $b->kanban(['claim', $id], ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session-b']);

    expect($gave->getExitCode())->toBe(9, $gave->getErrorOutput())
        ->and($gave->getErrorOutput())->toContain('push kept being rejected')
        ->and($b->read($id)['claim'])->toBeNull()
        ->and($b->read($id)['stage'])->toBe('ready')
        ->and(trim($b->boardGit('rev-list', '--count', 'origin/kanban..HEAD')))->toBe('0');
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
    while (is_file($a->root.'/.git/laravel-house/sync.lock') && ! flock(fopen($a->root.'/.git/laravel-house/sync.lock', 'r'), LOCK_EX | LOCK_NB) && microtime(true) < $deadline) {
        usleep(50000);
    }
});

it('keeps a write that is already committed when the host cannot start the background runner', function () {
    [$origin, $a] = published();
    $dir = sys_get_temp_dir().'/kanban-ini-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/no-exec.ini", "disable_functions=exec\n");

    $made = $a->kanban(['new', 'project/work', 'Committed anyway'], ['KANBAN_SYNC' => 'on', 'PHP_INI_SCAN_DIR' => ":{$dir}"]);

    expect($made->getExitCode())->toBe(0, $made->getOutput().$made->getErrorOutput())
        ->and($a->boardLog()[0])->toContain('created');
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

it('treats 1, true and yes like on for KANBAN_SYNC and reports it as on', function (string $value) {
    [$origin, $a] = published();
    $id = $a->readyCard('Synced with '.$value);
    $a->ok('sync');
    $env = ['KANBAN_SYNC' => $value, 'KANBAN_SESSION' => 'session-a'];

    $a->ok(['claim', $id], $env);

    expect($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-a"')
        ->and($a->ok(['status', '--json'], $env))->toContain('"sync": "on"');
})->with(['1', 'true', 'yes']);

/** A synced card on project/work and an empty second board, on two clones. */
function movable(): array
{
    [$origin, $a, $b] = published();
    $a->ok(['board', 'platform/tooling', 'Tooling']);
    $id = $a->card('Movable card', ['--accept=One']);
    $a->ok('sync');
    $b->ok('sync');

    return [$origin, $a, $b, $id];
}

it('keeps the id of a card that was moved to another board and edited a lot before syncing', function () {
    [$origin, $a, $b, $id] = movable();
    $a->ok(['set', $id, 'body=@-'], [], implode("\n", array_map(fn (int $n) => "Paragraph {$n} of a long rewritten body that rename detection cannot pair with the short original.", range(1, 40))));
    $a->ok(['move', $id, '--board=platform/tooling']);

    $sync = $a->ok('sync');

    expect($sync)->not->toContain('renamed')
        ->and($origin->show("kanban:platform/tooling/{$id}.json"))->toContain($id)
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->root."/docs/kanban/project/work/{$id}.json")->not->toBeFile()
        ->and($a->ok('validate'))->toContain('ok: 1 cards');
});

it('syncs a card moved on one machine and edited on another, whichever syncs first', function (bool $mover) {
    [$origin, $a, $b, $id] = movable();
    $b->ok(['set', $id, 'priority=high']);
    $a->ok(['set', $id, 'body=@-'], [], implode("\n", array_map(fn (int $n) => "Paragraph {$n} of a long rewritten body.", range(1, 40))));
    $a->ok(['move', $id, '--board=platform/tooling']);
    [$first, $second] = $mover ? [$a, $b] : [$b, $a];

    $first->ok('sync');
    $sync = $second->kanban('sync');

    expect($sync->getExitCode())->toBe(0, $sync->getErrorOutput());
    $first->ok('sync');
    $merged = $a->read($id);
    expect($merged)->toBe($b->read($id))
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->root."/docs/kanban/project/work/{$id}.json")->not->toBeFile()
        ->and($merged['priority'])->toBe('high')
        ->and($merged['body'])->toContain('Paragraph 40')
        ->and($a->ok('validate'))->toContain('ok: 1 cards')
        ->and(trim($a->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'HEAD')));
})->with([[true], [false]]);

it('lets origin win when both machines moved the same card, and keeps both machines\' edits', function () {
    [$origin, $a, $b, $id] = movable();
    $a->ok(['board', 'platform/ops', 'Ops']);
    $a->ok('sync');
    $b->ok('sync');
    $a->ok(['set', $id, 'priority=high']);
    $a->ok(['move', $id, '--board=platform/tooling']);
    $b->ok(['set', $id, 'labels=+area:pdf']);
    $b->ok(['move', $id, '--board=platform/ops']);

    $a->ok('sync');
    $sync = $b->kanban('sync');

    expect($sync->getExitCode())->toBe(0, $sync->getErrorOutput());
    $a->ok('sync');
    $merged = $a->read($id);
    expect($merged)->toBe($b->read($id))
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->root."/docs/kanban/platform/ops/{$id}.json")->not->toBeFile()
        ->and($merged['priority'])->toBe('high')
        ->and($merged['labels'])->toBe(['area:pdf'])
        ->and($a->ok('validate'))->toContain('ok: 1 cards');
});

it('checks blocked, area and capacity against origin\'s board when sync is on, not against a stale view', function () {
    [$origin, $a, $b] = published();
    $blocked = $a->readyCard('Blocked upstream');
    $first = $a->readyCard('First in the area', ['--label=area:billing']);
    $second = $a->readyCard('Second in the area', ['--label=area:billing']);
    $a->ok('sync');
    $b->ok('sync');
    $on = ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session'];

    $a->ok(['set', $blocked, 'blocked=hold on the owner']);
    $a->ok(['claim', $first], $on);
    $a->ok('sync');

    $blockedClaim = $b->kanban(['claim', $blocked], $on);
    $areaClaim = $b->kanban(['claim', $second], $on);

    expect($blockedClaim->getExitCode())->toBe(3, $blockedClaim->getErrorOutput())
        ->and($blockedClaim->getErrorOutput())->toContain('refused')
        ->and($areaClaim->getExitCode())->toBe(3, $areaClaim->getErrorOutput())
        ->and($b->read($blocked)['stage'])->toBe('ready')
        ->and($b->read($second)['stage'])->toBe('ready')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'origin/kanban')));
});

it('does not report a remote outage when syncs overlap on one clone', function () {
    [$origin, $a, $b] = published();
    foreach (range(1, 3) as $round) {
        $a->card("News {$round}");
        $a->ok('sync');

        $runs = array_map(fn () => $b->start(['sync']), range(1, 4));
        foreach ($runs as $run) {
            $run->wait();
        }

        expect(array_map(fn ($run) => $run->getExitCode(), $runs))->each->toBe(0);
        expect(implode('', array_map(fn ($run) => $run->getErrorOutput(), $runs)))->not->toContain('remote unreachable');
    }
});

it('drops its claim commit when the fetch after a rejected claim push fails', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Phantom claim');
    $a->ok('sync');
    $b->ok('sync');
    $on = ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session-b'];
    $before = trim($b->boardGit('rev-parse', 'HEAD'));

    $hooks = Sandbox::tmp();
    $php = PHP_BINARY;
    $bin = Sandbox::package().'/bin/kanban';
    file_put_contents("{$hooks}/pre-push", <<<SH
        #!/bin/sh
        [ -f "{$hooks}/done" ] && exit 0
        touch "{$hooks}/done"
        cd "{$a->root}" && "{$php}" "{$bin}" new project/work "Moved origin" >/dev/null 2>&1 && "{$php}" "{$bin}" sync >/dev/null 2>&1
        touch "{$b->root}/.git/refs/remotes/origin/kanban.lock"
        exit 0
        SH);
    chmod("{$hooks}/pre-push", 0755);
    $b->git('config', 'core.hooksPath', $hooks);

    $claim = $b->kanban(['claim', $id], $on);
    @unlink($b->root.'/.git/refs/remotes/origin/kanban.lock');

    expect($claim->getExitCode())->toBe(9, $claim->getErrorOutput())
        ->and($b->read($id)['stage'])->toBe('ready')
        ->and(trim($b->boardGit('rev-parse', 'HEAD')))->toBe($before);
});

it('starts a card origin has since unblocked, even though this clone has not synced', function () {
    [$origin, $a, $b] = published();
    $id = $a->readyCard('Unblocked upstream');
    $a->ok(['set', $id, 'blocked=waiting']);
    $a->ok('sync');
    $b->ok('sync');
    $a->ok(['set', $id, 'blocked=']);
    $a->ok('sync');
    $on = ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'session-b'];

    $claim = $b->kanban(['claim', $id], $on);

    expect($claim->getExitCode())->toBe(0, $claim->getErrorOutput())
        ->and($origin->show("kanban:project/work/{$id}.json"))->toContain('"session": "session-b"');
});

it('leaves a moved card where it is when the undo commit before a sync fails, and syncs cleanly afterwards', function () {
    [$origin, $a, $b, $id] = movable();
    $b->ok(['set', $id, 'priority=high']);
    $b->ok('sync');
    $a->ok(['move', $id, '--board=platform/tooling']);
    $gitdir = trim($a->boardGit('rev-parse', '--absolute-git-dir'));
    $a->boardGit('fetch', '-q');
    touch($gitdir.'/index.lock');

    $failed = $a->kanban('sync');
    unlink($gitdir.'/index.lock');

    expect($failed->getExitCode())->not->toBe(0)
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->root."/docs/kanban/project/work/{$id}.json")->not->toBeFile()
        ->and(trim($a->boardGit('status', '--porcelain')))->toBe('');

    $ok = $a->kanban('sync');
    expect($ok->getExitCode())->toBe(0, $ok->getErrorOutput())
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->read($id)['priority'])->toBe('high')
        ->and($a->ok('validate'))->toContain('ok: 1 cards');
});

it('keeps a local edit of a card origin moved when setting it aside for the merge fails', function () {
    [$origin, $a, $b, $id] = movable();
    $b->ok(['move', $id, '--board=platform/tooling']);
    $b->ok(['set', $id, 'body=@-'], [], implode("\n", array_map(fn (int $n) => "Paragraph {$n} rewritten so rename detection cannot pair it.", range(1, 40))));
    $b->ok('sync');
    $a->ok(['set', $id, 'priority=high']);
    $a->boardGit('fetch', '-q');
    $gitdir = trim($a->boardGit('rev-parse', '--absolute-git-dir'));
    touch($gitdir.'/index.lock');

    $failed = $a->kanban('sync');
    unlink($gitdir.'/index.lock');

    expect($failed->getExitCode())->not->toBe(0)
        ->and($a->read($id)['priority'])->toBe('high')
        ->and(trim($a->boardGit('status', '--porcelain')))->toBe('');

    $ok = $a->kanban('sync');
    expect($ok->getExitCode())->toBe(0, $ok->getErrorOutput())
        ->and($a->read($id)['priority'])->toBe('high')
        ->and($a->read($id)['body'])->toContain('Paragraph 40')
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->ok('validate'))->toContain('ok: 1 cards');
});

it('keeps its separate local board commits when a sync that had to undo a move fails on a conflict', function () {
    [$origin, $a, $b, $id] = movable();
    $other = $a->card('Edited on both sides');
    $a->ok('sync');
    $b->ok('sync');
    $b->ok(['set', $other, 'title=Title from B']);
    $b->ok('sync');
    // origin's copy of the card carries another creation time: the merge driver refuses to merge two different cards
    $file = "{$b->root}/docs/kanban/project/work/{$other}.json";
    $card = json_decode(file_get_contents($file), true);
    $card['created'] = '2020-01-01T00:00:00.000+00:00';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $b->boardGit('commit', '-q', '-am', 'another card on the same path');
    $b->boardGit('push', '-q', 'origin', 'kanban');
    $a->ok(['set', $other, 'title=Title from A']);
    $a->ok(['set', $other, 'priority=high']);
    $a->ok(['move', $id, '--board=platform/tooling']);
    $history = $a->boardLog();

    $failed = $a->kanban('sync');

    expect($failed->getExitCode())->toBe(5)
        ->and($a->boardLog())->toBe($history)
        ->and($a->root."/docs/kanban/platform/tooling/{$id}.json")->toBeFile()
        ->and($a->read($other)['title'])->toBe('Title from A')
        ->and(trim($a->boardGit('status', '--porcelain')))->toBe('');
});

/** What the last sync of a clone recorded. */
function syncRecord(Sandbox $s): array
{
    return json_decode((string) @file_get_contents($s->root.'/.git/laravel-house/sync.status.json'), true) ?: [];
}

/** Waits until no detached sync runner holds the clone's sync lock. */
function drainSync(Sandbox $s): void
{
    $deadline = microtime(true) + 15;
    $file = $s->root.'/.git/laravel-house/sync.lock';
    while (is_file($file) && ! flock($handle = fopen($file, 'r'), LOCK_EX | LOCK_NB) && microtime(true) < $deadline) {
        usleep(50000);
    }
}

it('shows a sync that keeps failing as a notice, and clears it when the push lands', function () {
    [$origin, $a] = published();
    UiSandbox::boot($a->root);
    $a->card('Waiting to be pushed');
    $quiet = $this->getJson('/kanban/_api/boards')->assertOk();

    rename($origin->path, $origin->path.'.away');
    expect($a->kanban('sync')->getExitCode())->toBe(9);
    $blip = $this->getJson('/kanban/_api/boards')->assertOk();

    expect($blip->json('notices'))->toBe([]);

    expect($a->kanban('sync')->getExitCode())->toBe(9);
    $down = $this->getJson('/kanban/_api/boards')->assertOk();
    expect(implode(' ', $down->json('notices')))->toContain('Not pushed: 1 commit')
        ->and($down->headers->get('ETag'))->not->toBe($quiet->headers->get('ETag'))
        ->and($a->ok('status'))->toContain('last sync failed')
        ->and(json_decode($a->ok(['status', '--json']), true)['last_sync'])->toMatchArray(['state' => 'failed', 'failures' => 2, 'ahead' => 1]);

    rename($origin->path.'.away', $origin->path);
    $a->ok('sync');
    $back = $this->getJson('/kanban/_api/boards')->assertOk();

    expect($back->json('notices'))->toBe([])
        ->and($back->headers->get('ETag'))->toBe($quiet->headers->get('ETag'))
        ->and($a->ok('status'))->not->toContain('last sync failed');
});

it('stops and says so when two clones together make a dependency cycle', function () {
    [$origin, $a, $b] = published();
    $one = $a->card('One');
    $two = $a->card('Two');
    $a->ok('sync');
    $b->ok('sync');
    $a->ok(['set', $one, "depends_on=+{$two}"]);
    $b->ok(['set', $two, "depends_on=+{$one}"]);
    $a->ok('sync');

    $stopped = $b->kanban('sync');

    $again = $b->kanban('sync');
    UiSandbox::boot($b->root);
    $notices = implode(' ', $this->getJson('/kanban/_api/boards')->assertOk()->json('notices'));
    expect($stopped->getExitCode())->toBe(2)
        ->and($stopped->getErrorOutput())->toContain('invalid after the pull')
        ->and($again->getExitCode())->toBe(2)
        ->and(syncRecord($b))->toMatchArray(['state' => 'failed', 'kind' => 'invalid', 'ahead' => 1])
        ->and($notices)->toContain('Sync stopped after a pull')->toContain('dependency cycle');
});

it('keeps saying a pulled board is invalid, on a clone that has nothing of its own to push', function () {
    [$origin, $a, $b] = published();
    $one = $a->card('One');
    $two = $a->card('Two');
    $a->ok('sync');
    $b->ok('sync');
    // pushed around the validation, as an older version or a hand edit could
    foreach ([[$one, $two], [$two, $one]] as [$card, $on]) {
        $file = glob($a->root."/docs/kanban/*/*/{$card}.json")[0];
        $data = json_decode(file_get_contents($file), true);
        $data['depends_on'] = [$on];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    $a->boardGit('commit', '-q', '-am', 'a cycle');
    $a->boardGit('push', '-q', 'origin', 'kanban');

    $first = $b->kanban('sync');
    $second = $b->kanban('sync');

    expect($first->getExitCode())->toBe(2)
        ->and($second->getExitCode())->toBe(2, $second->getOutput().$second->getErrorOutput())
        ->and(syncRecord($b))->toMatchArray(['state' => 'failed', 'kind' => 'invalid']);
});

it('tries a background sync again before it gives up, and shows what it could not push', function () {
    [$origin, $a] = published();
    rename($origin->path, $origin->path.'.away');

    $a->card('Cannot leave yet', env: ['KANBAN_SYNC' => 'on']);

    $deadline = microtime(true) + 30;
    while ((syncRecord($a)['failures'] ?? 0) < 1 && microtime(true) < $deadline) {
        usleep(100000);
    }
    drainSync($a);
    // the three tries of one run are one failure: a short outage must not reach the two that show the notice
    expect(syncRecord($a))->toMatchArray(['state' => 'failed', 'kind' => 'remote', 'ahead' => 1, 'failures' => 1]);

    $a->card('Second run', env: ['KANBAN_SYNC' => 'on']);
    while ((syncRecord($a)['failures'] ?? 0) < 2 && microtime(true) < $deadline) {
        usleep(100000);
    }
    drainSync($a);
    expect(syncRecord($a))->toMatchArray(['state' => 'failed', 'ahead' => 2, 'failures' => 2]);
});

it('recovers by itself from a short outage', function () {
    [$origin, $a] = published();
    rename($origin->path, $origin->path.'.away');

    $id = $a->card('Leaves after the outage', env: ['KANBAN_SYNC' => 'on']);

    $deadline = microtime(true) + 15;
    while ((syncRecord($a)['failures'] ?? 0) < 1 && microtime(true) < $deadline) {
        usleep(50000);
    }
    rename($origin->path.'.away', $origin->path);
    while ((syncRecord($a)['state'] ?? '') !== 'ok' && microtime(true) < $deadline) {
        usleep(100000);
    }
    drainSync($a);
    expect(syncRecord($a))->toMatchArray(['state' => 'ok', 'failures' => 0])
        ->and($origin->log('kanban')[0])->toBe("{$id} created [owner]");
});

it('keeps the previous sync record when the board lock is busy', function () {
    [$origin, $a] = published();
    $a->card('Ahead of origin');
    rename($origin->path, $origin->path.'.away');
    $a->kanban('sync');
    rename($origin->path.'.away', $origin->path);
    $held = fopen($a->root.'/.git/laravel-house/lock', 'c');
    flock($held, LOCK_EX);

    $busy = $a->kanban('sync');

    flock($held, LOCK_UN);
    expect($busy->getExitCode())->not->toBe(0)
        ->and(syncRecord($a))->toMatchArray(['state' => 'failed', 'failures' => 1]);
});

/** The UI of a clone, booted with the sync settings of a team member. */
function teamUi(Sandbox $clone, int $every = 5): void
{
    UiSandbox::boot($clone->root, 'local', ['kanban.sync' => 'on', 'kanban.pull_seconds' => $every]);
}

/** Lets the next request count as the first after a whole interval. */
function elapse(Sandbox $clone): void
{
    foreach (['sync.tick', 'sync.requested'] as $file) {
        @unlink($clone->root.'/.git/laravel-house/'.$file);
    }
}

/** Waits until $done() holds, up to 20 s. */
function within(callable $done): bool
{
    $deadline = microtime(true) + 20;
    while (! $done() && microtime(true) < $deadline) {
        usleep(100000);
    }

    return $done();
}

it('pulls what a teammate pushed while the board stays open, without a write of its own', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared');
    $a->ok('sync');
    $b->ok('sync');
    teamUi($a);
    $this->getJson('/kanban/_api/boards')->assertOk();
    drainSync($a);
    $seen = $this->getJson('/kanban/_api/boards')->headers->get('ETag');
    $b->ok(['set', $id, 'title=Renamed by B']);
    $b->ok('sync');
    elapse($a);

    $this->getJson('/kanban/_api/boards')->assertOk();

    expect(within(fn () => $a->read($id)['title'] === 'Renamed by B'))->toBeTrue();
    drainSync($a);
    expect($this->getJson('/kanban/_api/boards')->headers->get('ETag'))->not->toBe($seen);
});

it('asks for a sync at most once per interval, however often the board is polled', function () {
    [$origin, $a] = published();
    teamUi($a);
    $runtime = $a->root.'/.git/laravel-house';

    $this->getJson('/kanban/_api/boards')->assertOk();
    drainSync($a);
    // a stamp two seconds old, well inside the interval: every poll that follows must leave it alone
    $stamp = time() - 2;
    touch("{$runtime}/sync.tick", $stamp);
    touch("{$runtime}/sync.requested", $stamp);
    foreach (range(1, 5) as $poll) {
        $this->getJson('/kanban/_api/boards')->assertOk();
    }

    expect(filemtime("{$runtime}/sync.tick"))->toBe($stamp)->and(filemtime("{$runtime}/sync.requested"))->toBe($stamp);
});

it('treats a stamp from the future as due', function () {
    [$origin, $a] = published();
    teamUi($a);
    $runtime = $a->root.'/.git/laravel-house';
    $this->getJson('/kanban/_api/boards')->assertOk();
    drainSync($a);
    touch("{$runtime}/sync.tick", time() + 3600);
    touch("{$runtime}/sync.requested", time() + 3600);

    $this->getJson('/kanban/_api/boards')->assertOk();

    expect(filemtime("{$runtime}/sync.tick"))->toBeLessThan(time() + 60);
    drainSync($a);
});

it('never starts a sync for a clone that has no remote, and stamps the interval all the same', function () {
    $solo = Sandbox::create('solo');
    $solo->install('ACME');
    teamUi($solo);
    $runtime = $solo->root.'/.git/laravel-house';

    foreach (range(1, 4) as $poll) {
        $this->getJson('/kanban/_api/boards')->assertOk();
    }

    expect(is_file("{$runtime}/sync.tick"))->toBeTrue()->and(is_file("{$runtime}/sync.requested"))->toBeFalse();
});

it('still pulls on a clone whose own push keeps failing', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared');
    $a->ok('sync');
    $b->ok('sync');
    $a->card('Only here');
    racingPush($a, 'exit 1', every: true);
    $b->ok(['set', $id, 'title=Renamed by B']);
    $b->ok('sync');
    teamUi($a);
    elapse($a);

    $this->getJson('/kanban/_api/boards')->assertOk();

    expect(within(fn () => $a->read($id)['title'] === 'Renamed by B' && (syncRecord($a)['state'] ?? '') === 'failed'))->toBeTrue();
    drainSync($a);
    expect(syncRecord($a))->toMatchArray(['state' => 'failed', 'ahead' => 1]);
});

it('converges a headless clone through status and next, with no UI open', function () {
    [$origin, $a, $b] = published();
    $id = $a->card('Shared');
    $a->ok('sync');
    $b->ok('sync');
    $b->ok(['set', $id, 'title=Renamed by B']);
    $b->ok('sync');
    $on = ['KANBAN_SYNC' => 'on'];

    $a->ok('status', $on);

    expect(within(fn () => $a->read($id)['title'] === 'Renamed by B'))->toBeTrue();
    drainSync($a);
    $b->ok(['set', $id, 'title=Renamed again']);
    $b->ok('sync');
    elapse($a);

    $a->ok('next', $on);

    expect(within(fn () => $a->read($id)['title'] === 'Renamed again'))->toBeTrue();
    drainSync($a);
});

it('does not wait for the write lock when there is nothing to pull or push', function () {
    [$origin, $a] = published();
    $held = fopen($a->root.'/.git/laravel-house/lock', 'c');
    flock($held, LOCK_EX);
    $started = microtime(true);

    $idle = $a->kanban('sync');

    flock($held, LOCK_UN);
    expect($idle->getExitCode())->toBe(0, $idle->getErrorOutput())
        ->and($idle->getOutput())->toBe("sync: up to date\n")
        ->and(microtime(true) - $started)->toBeLessThan(8.0);
});

it('publishes the board when it is installed next to an origin, and says so', function () {
    $origin = Origin::create();
    $s = Sandbox::create('installed')->addRemote($origin);
    config(['kanban.sync' => 'auto']);

    $out = $s->install('ACME');

    expect($out)->toContain('sync: pushed')->toContain('sync is on')
        ->and($origin->log('kanban'))->not->toBe([])
        ->and($s->ok('status', ['KANBAN_SYNC' => 'auto']))->toContain(', sync auto (on), ');
});

it('keeps an installed board local when there is no origin, and says nothing about sync', function () {
    $s = Sandbox::create('solo');
    config(['kanban.sync' => 'auto']);

    $out = $s->install('ACME');
    $s->card('Just here', env: ['KANBAN_SYNC' => 'auto']);

    expect($out)->not->toContain('sync is on')
        ->and($s->ok('status', ['KANBAN_SYNC' => 'auto']))->toContain(', sync off, ')
        ->and(is_file($s->root.'/.git/laravel-house/sync.requested'))->toBeFalse();
});

it('pushes each write by itself with the default setting when an origin exists', function () {
    [$origin, $a] = published();

    $id = $a->card('Pushed by itself', env: ['KANBAN_SYNC' => 'auto']);

    expect(within(fn () => ($origin->log('kanban')[0] ?? '') === "{$id} created [owner]"))->toBeTrue();
    drainSync($a);
});

it('keeps writes local with sync off, or with a value it does not know, and warns when the board is published', function () {
    [$origin, $a] = published();

    $a->card('Stays here', env: ['KANBAN_SYNC' => 'off']);
    $a->card('Nor does this one', env: ['KANBAN_SYNC' => 'maybe']);

    expect($origin->log('kanban'))->toHaveCount(1)
        ->and($a->ok('status', ['KANBAN_SYNC' => 'off']))->toContain('sync off but this board is published: claims are not coordinated with other machines');
});

it('refuses a claim while the remote cannot be reached, naming the way to claim locally', function () {
    [$origin, $a] = published();
    $id = $a->readyCard('Offline');
    $a->ok('sync');
    rename($origin->path, $origin->path.'.away');

    $refused = $a->kanban(['claim', $id], ['KANBAN_SYNC' => 'auto', 'KANBAN_SESSION' => 's1']);
    $started = $a->kanban(['start', $id], ['KANBAN_SYNC' => 'auto', 'KANBAN_SESSION' => 's1']);
    $local = $a->kanban(['claim', $id], ['KANBAN_SYNC' => 'off', 'KANBAN_SESSION' => 's1']);

    expect($refused->getExitCode())->toBe(9, $refused->getErrorOutput())
        ->and($refused->getErrorOutput())->toContain('run the same command with KANBAN_SYNC=off in front')
        ->and($started->getExitCode())->toBe(9)->and($started->getErrorOutput())->toContain('run the same command with KANBAN_SYNC=off in front')
        ->and($a->read($id)['claim'])->not->toBeNull()
        ->and($local->getExitCode())->toBe(0, $local->getErrorOutput());
});

it('converges three clones that write to the same cards at once: identical boards, no note lost, every displaced title kept', function () {
    [$origin, $a, $b] = published();
    $c = $origin->clone('c');
    $c->ok('attach');
    $clones = [$a, $b, $c];
    $ids = [$a->card('One'), $a->card('Two'), $a->card('Three')];
    $a->ok('sync');
    $b->ok('sync');
    $c->ok('sync');

    // unsynced: each person edits every card (a note, a title, a label of their own), one after the other in time
    $notes = [];
    foreach ($clones as $n => $clone) {
        foreach ($ids as $id) {
            usleep(5000);
            $clone->ok(['set', $id, "note=note {$n} on {$id}", "title=Title from {$n}", "labels=+person-{$n}"]);
            $notes[$id][] = "note {$n} on {$id}";
        }
    }
    // all three sync at the same moment, then a few rounds until nobody has anything left to give or take
    $runs = array_map(fn ($clone) => $clone->start(['sync']), $clones);
    foreach ($runs as $run) {
        $run->wait();
    }
    foreach (range(1, 3) as $round) {
        foreach ($clones as $clone) {
            $clone->kanban('sync');
        }
    }

    foreach ($ids as $id) {
        $cards = array_map(fn ($clone) => $clone->read($id), $clones);
        expect($cards[1])->toBe($cards[0])->and($cards[2])->toBe($cards[0]);
        $log = $cards[0]['log'];
        $titles = array_merge([$cards[0]['title']], array_column(array_filter($log, fn ($e) => $e['event'] === 'conflict' && $e['field'] === 'title'), 'lost'));
        expect(array_column(array_filter($log, fn ($e) => $e['event'] === 'note'), 'text'))->toEqualCanonicalizing($notes[$id])
            ->and($cards[0]['labels'])->toEqualCanonicalizing(['person-0', 'person-1', 'person-2'])
            ->and($titles)->toContain('Title from 0')->toContain('Title from 1')->toContain('Title from 2');
    }
    expect(trim($a->boardGit('rev-parse', 'HEAD')))->toBe(trim($b->boardGit('rev-parse', 'HEAD')))->toBe(trim($c->boardGit('rev-parse', 'HEAD')))
        ->and($c->ok('validate'))->toContain('ok: 3 cards');
});

it('lets doctor say what sync is doing here, and warn about a published board that is not synced', function () {
    [$origin, $a] = published();

    $on = $a->kanban('doctor', ['KANBAN_SYNC' => 'auto']);
    expect($on->getOutput())->toContain("ok sync auto (on)\n");

    $off = $a->kanban('doctor', ['KANBAN_SYNC' => 'off']);
    expect($off->getOutput())->toContain('warn sync off but this board is published');

    rename($origin->path, $origin->path.'.away');
    $a->kanban('sync');
    $a->kanban('sync');
    expect($a->kanban('doctor', ['KANBAN_SYNC' => 'auto'])->getOutput())->toContain('warn last sync failed (2 in a row)');
});
