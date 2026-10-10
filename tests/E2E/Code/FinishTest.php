<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create();
    configureMerge($this->code);
    $this->code->mountWorktree();
    $this->code->defaults = ['FAKE_DOCKER_SERVE' => '1'];
    $this->origin = Origin::create();
    $this->code->sandbox->addRemote($this->origin);
});

/** config/kanban.php with $overrides, committed; its gate passes only in a container (FakeDocker's exec marks what it runs). */
function configureMerge(CodeSandbox $code, array $overrides = []): void
{
    $code->configure($overrides + ['gates' => ['report' => ['test "$FAKE_DOCKER_EXEC" = 1']]]);
    $code->sandbox->git('commit', '-q', '-am', 'config');
    if (trim($code->sandbox->git('remote')) !== '') {
        $code->sandbox->git('push', '-q', 'origin', 'main');
    }
}

function mergeProject(CodeSandbox $code): string
{
    return 'acme-merge-'.substr(sha1(realpath($code->root())), 0, 8);
}

/** A commit on main, pushed: the remote's main moves. */
function pushMain(CodeSandbox $code, string $file, string $content): string
{
    @mkdir(dirname($code->root().'/'.$file), 0775, true);
    $sha = $code->commitMain($file, $content);
    $code->sandbox->git('push', '-q', 'origin', 'main');

    return $sha;
}

/** @return list<array<string, mixed>> the card's `merge` log entries */
function merges(CodeSandbox $code, string $id): array
{
    return array_values(array_filter($code->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'merge'));
}

it('merges an approved card in the merge clone, pushes main and tears the card down', function () {
    $code = $this->code;
    configureMerge($code, ['finish' => ['after' => ['echo migrated > after.txt']]]);
    $before = trim($code->sandbox->git('rev-parse', 'main'));
    $id = $code->started('Add login page');
    $name = basename($code->worktree($id));
    $wt = $code->worktree($id);
    $head = $code->commit($id, 'login.php', "<?php\n", 'Login page');
    $code->approve($id);
    $branch = $code->sandbox->read($id)['work']['branch'];
    $board = file_get_contents($code->root().'/docs/kanban/kanban.json');

    $output = $code->ok(['finish', $id]);

    $sha = trim($code->sandbox->git('rev-parse', 'main'));
    $merge = $code->mergeClone();
    expect($output)->toBe(implode("\n", [
        "merge lease taken for {$id}",
        "{$id}: gates and finish.check pass on the merged tree",
        "merged {$id} into main ".substr($sha, 0, 7),
        "{$id} review→done",
        "stack down acme-wt-{$name}; slot released",
        "removed worktree .claude/worktrees/{$name}",
        "deleted branch {$branch}",
        'main checkout at '.substr($sha, 0, 7),
        'after: echo migrated > after.txt ok',
    ])."\n")
        ->and($this->origin->log('main')[0])->toBe("{$id}: Add login page")
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($sha)
        ->and(trim($code->sandbox->git('log', '-1', '--format=%P', $sha)))->toBe("{$before} {$head}")
        ->and(is_file($code->root().'/login.php'))->toBeTrue()
        ->and(trim(file_get_contents($code->root().'/after.txt')))->toBe('migrated')
        ->and(is_dir($wt))->toBeFalse()
        ->and(trim($code->sandbox->git('branch', '--list', $branch)))->toBe('')
        ->and(trim($code->sandbox->git('for-each-ref', 'refs/merge-queue/')))->toBe('')
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and(file_get_contents($code->root().'/docs/kanban/kanban.json'))->toBe($board)
        ->and(is_file($merge.'/login.php'))->toBeTrue()
        ->and($code->stacks())->toHaveCount(1)
        ->and($code->stacks()[0])->toMatchArray(['project' => mergeProject($code), 'purpose' => 'merge', 'worktree' => realpath($merge)])
        ->and($code->calls())->toContain("compose --project-directory {$merge} -f {$merge}/docker-compose.local.yml -p ".mergeProject($code).' up -d --build');

    $card = $code->sandbox->read($id);
    expect($card['stage'])->toBe('done')
        ->and($card['claim'])->toBeNull()
        ->and(array_keys($card['work']))->toBe(['branch', 'base', 'merge', 'started', 'finished'])
        ->and($card['work']['merge'])->toBe($sha);
});

it('refuses a card the queue does not hold, and waits for one that is not its turn', function (Closure $arrange, int $exit, string $message) {
    $code = $this->code;
    $id = $code->started('Refused finish');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $arrange($code, $id);
    $main = trim($code->sandbox->git('rev-parse', 'main'));

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe($exit)
        ->and($run->getErrorOutput())->toContain($message)
        ->and(trim($code->sandbox->git('rev-parse', 'main')))->toBe($main)
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and(is_dir($code->mergeClone()))->toBeFalse();
})->with([
    'not in review' => [fn () => null, 3, 'is doing, not review'],
    'no approval' => [function (CodeSandbox $c, string $id) {
        $c->ok(['move', $id, 'review', '--force'], ['KANBAN_SESSION' => 's1']);
    }, 3, 'has no approval: an evaluator approves it first'],
    'blocked' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        $c->sandbox->ok(['set', $id, 'blocked=waits on the owner']);
    }, 3, 'is blocked: waits on the owner'],
    'finish.check empty' => [function (CodeSandbox $c, string $id) {
        configureMerge($c, ['finish' => ['check' => []]]);
        $c->approve($id);
    }, 3, 'finish.check names no suite: no card merges until it does'],
    'stacks off' => [function (CodeSandbox $c, string $id) {
        configureMerge($c, ['stack' => ['compose_file' => null]]);
        $c->approve($id);
    }, 3, 'the merge queue runs its checks in the merge stack: set stack.compose_file'],
    'another card first' => [function (CodeSandbox $c, string $id) {
        $first = $c->started('Older approval');
        $c->commit($first, 'older.php', "<?php\n");
        $c->approve($first, at: '2026-01-01T00:00:00.000+00:00');
        $c->approve($id);
    }, 11, 'waits its turn: '],
    'a live agent' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        @mkdir($c->root().'/.git/laravel-house/agents', 0775, true);
        file_put_contents($c->root().'/.git/laravel-house/agents/a1.json', json_encode([
            'agent_id' => 'a1', 'agent_type' => 'kanban-evaluator', 'card' => $id, 'worktree' => $c->worktree($id), 'stopped_at' => null,
        ]));
    }, 11, 'an agent is still bound to it (kanban-evaluator)'],
]);

it('passes over a card of this machine whose agent is still bound to it, and merges the next', function () {
    $code = $this->code;
    $busy = $code->started('Older approval');
    $code->commit($busy, 'older.php', "<?php\n");
    $code->approve($busy, at: '2026-01-01T00:00:00.000+00:00');
    @mkdir($code->root().'/.git/laravel-house/agents', 0775, true);
    file_put_contents($code->root().'/.git/laravel-house/agents/a1.json', json_encode([
        'agent_id' => 'a1', 'agent_type' => 'kanban-evaluator', 'card' => $busy, 'worktree' => $code->worktree($busy), 'stopped_at' => null,
    ]));
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish']);
    $none = $code->kanban(['finish']);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($code->sandbox->read($busy)['stage'])->toBe('review')
        ->and($none->getExitCode())->toBe(11)
        ->and($none->getErrorOutput())->toContain("nothing to merge here: {$busy} passed over (an agent is still bound to it (kanban-evaluator))");
});

it('waits while another finish runs in this checkout', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    @mkdir($code->root().'/.git/laravel-house', 0775, true);
    $lock = fopen($code->root().'/.git/laravel-house/merge.run.lock', 'c');
    flock($lock, LOCK_EX);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(11)
        ->and($run->getErrorOutput())->toContain('a merge runs here')
        ->and($code->sandbox->read($id)['stage'])->toBe('review');
});

it('exits 6 and leaves the card in review while another main session holds the orchestrator lease', function () {
    $code = $this->code;
    $id = $code->started('Add login page', ['KANBAN_SESSION' => 'session-a']);
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id], ['KANBAN_SESSION' => 'session-b']);

    expect($run->getExitCode())->toBe(6)
        ->and($run->getErrorOutput())->toContain('another session holds the orchestrator lease (session-a')
        ->and($code->sandbox->read($id)['stage'])->toBe('review')
        ->and($code->lease())->toBeNull();
});

it('clears an approval its branch moved past, and merges nothing', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $head = $code->commit($id, 'more.php', "<?php\n");

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(13)
        ->and($run->getOutput())->toContain("{$id}: its branch is at ".substr($head, 0, 7).', past its approval of')
        ->and($code->sandbox->read($id)['work']['approved'])->toBeNull()
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'stale', 'head' => $head]))
        ->and($code->lease())->toBeNull()
        ->and($this->origin->log('main')[0])->not->toContain($id);
});

it('stops on a conflict for the merger, holding the lease and moving nothing', function () {
    $code = $this->code;
    $id = $code->started('Conflict');
    $head = $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    $base = pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(12)
        ->and($run->getOutput())->toContain("{$id}: conflicts in app.php; the merger's turn, the merge lease stays held\n")
        ->toContain('merger: Agent(subagent_type="kanban-merger", description="'.$id.' merge Conflict", prompt="Card '.$id.'. Worktree '.$code->mergeClone().'")')
        ->and($code->mergeState())->toMatchArray(['card' => $id, 'phase' => 'conflict', 'conflicts' => ['app.php'], 'base' => $base, 'head' => $head, 'round' => 1])
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'conflict', 'files' => ['app.php'], 'base' => $base, 'round' => 1]))
        ->and($code->lease())->toMatchArray(['card' => $id])
        ->and(trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD')))->toBe($head)
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($base)
        ->and(file_get_contents($code->mergeClone().'/app.php'))->toContain('<<<<<<<')
        ->and(is_file($code->mergeClone().'/.git/MERGE_HEAD'))->toBeTrue();

    // the merger's turn: a finish meanwhile hands it back
    $again = $code->kanban(['finish', $id]);
    expect($again->getExitCode())->toBe(12)
        ->and(merges($code, $id))->toHaveCount(1);
});

it('blocks a card whose merge conflicts in files the merger may not edit, and lets the lease go', function (string $file) {
    $code = $this->code;
    $id = $code->started('Tune the agents');
    $code->commit($id, $file, "{\"card\": true}\n");
    $code->approve($id);
    pushMain($code, $file, "{\"main\": true}\n");

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain("{$id}: merge conflict in files the merger may not edit: {$file}; resolve it on the card's branch, then unblock it (the kanban skill's gotchas.md says how)")
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'review', 'blocked' => "merge conflict in files the merger may not edit: {$file}"])
        ->and(trim($code->sandbox->git('for-each-ref', "refs/merge-queue/{$id}/")))->toBe('')
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull();
})->with([
    'settings' => ['.claude/settings.json'],
    'a name git quotes' => ['.claude/agents/x"ü.md'],
]);

it('stops on a red suite for the merger once the base passes it, pushing nothing', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    $origin = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(12)
        ->and($run->getOutput())->toContain("{$id}: red: `test ! -e RED`; the merger's turn")
        ->and($code->mergeState())->toMatchArray(['phase' => 'red', 'failure' => ['step' => 'suite', 'command' => 'test ! -e RED', 'exit' => 1, 'tail' => '', 'base_rerun' => 'passed']])
        ->and($code->mergeState()['checked'])->toBe(trim($code->gitIn($code->mergeClone(), 'rev-parse', 'HEAD')))
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'red', 'step' => 'suite', 'command' => 'test ! -e RED', 'exit' => 1, 'base_rerun' => 'passed']))
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($origin)
        ->and(is_file($code->mergeClone().'/RED'))->toBeTrue();
});

it('runs finish.check in the merge container, on the merged tree and on the base alone', function () {
    $code = $this->code;
    $log = $code->root().'/../suite.log';
    configureMerge($code, ['finish' => ['check' => ["echo \"suite \$FAKE_DOCKER_EXEC\" >> {$log}; test ! -e RED"]]]);
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(12, $run->getOutput().$run->getErrorOutput())
        ->and($code->mergeState()['failure']['base_rerun'])->toBe('passed')
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe(['suite 1', 'suite 1']);
});

it('gives a red suite to the merger, never main red, when its rerun on the base runs past its timeout', function () {
    $code = $this->code;
    $check = 'if [ -e RED ]; then exit 1; fi; sleep 30';
    configureMerge($code, ['finish' => ['after' => [], 'check' => [['run' => $check, 'timeout' => 2]]]]);
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(12, $run->getOutput().$run->getErrorOutput())
        ->and($code->mergeState()['failure'])->toMatchArray(['step' => 'suite', 'exit' => 1, 'base_rerun' => 'timeout'])
        ->and(array_column(merges($code, $id), 'result'))->toBe(['red'])
        ->and($code->ok(['status']))->not->toContain('main is red');
});

it('aborts a merge: the lease free, the merge clone at the base, the card still queued; never mid-push', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $state = $code->mergeState();
    file_put_contents($code->root().'/.git/laravel-house/merge.json', json_encode(['phase' => 'pushing', 'merged' => str_repeat('a', 40)] + $state));

    $refused = $code->kanban(['finish', $id, '--abort']);
    file_put_contents($code->root().'/.git/laravel-house/merge.json', json_encode($state));
    $aborted = $code->kanban(['finish', $id, '--abort']);

    expect($refused->getExitCode())->toBe(11)
        ->and($refused->getErrorOutput())->toContain("a push of aaaaaaa is in flight: `kanban finish {$id}` settles it")
        ->and($aborted->getExitCode())->toBe(0)
        ->and($aborted->getOutput())->toContain("merge of {$id} aborted: the merge lease is free, the card stays queued")
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and(is_file($code->mergeClone().'/RED'))->toBeFalse()
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'review'])
        ->and($code->sandbox->read($id)['work']['approved'])->not->toBeNull();
});

it('lets the lease go once the merger\'s result took the card out of the queue', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    $code->kanban(['finish', $id]);
    file_put_contents($code->root().'/.git/laravel-house/merge.json', json_encode(['phase' => 'released'] + $code->mergeState()));

    $run = $code->kanban(['finish']);

    expect($run->getExitCode())->toBe(13)
        ->and($run->getOutput())->toContain("{$id}: its merger's result is on the card; merge lease given back")
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull();
});

it('serves the merge stack as `_merge`', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    $code->kanban(['finish', $id]);

    expect($code->ok(['stack', '_merge', 'url']))->toMatch('#^http://\S+:\d+\n$#')
        ->and($code->ok(['stack', 'list']))->toContain(mergeProject($code));
});

it('merges the approved head it pinned, not a commit the branch got after', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $wt = $code->worktree($id);
    $approved = $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    // a gate that commits to the card's branch and syncs it into main while the merge runs
    configureMerge($code, ['gates' => ['report' => ["git -C {$wt} commit -q --allow-empty -m late && {$code->root()}/vendor/bin/kanban context {$id} > /dev/null"]]]);

    $run = $code->kanban(['finish', $id]);

    $branch = $code->sandbox->read($id)['work']['branch'];
    expect($run->getExitCode())->toBe(10)
        ->and(trim($code->sandbox->git('rev-parse', 'main^2')))->toBe($approved)
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($run->getErrorOutput())->toContain("branch {$branch} kept: ")
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s', $branch)))->toBe('late');
});

it('records a card whose approved head is on main already as done, merging and checking nothing', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $head = $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $branch = $code->sandbox->read($id)['work']['branch'];
    $code->sandbox->git('fetch', '-q', $code->worktree($id), "{$branch}:{$branch}");
    $code->sandbox->git('merge', '-q', '--no-ff', '-m', 'by hand', $branch);
    $merge = trim($code->sandbox->git('rev-parse', 'HEAD'));
    pushMain($code, 'other.txt', "other\n");

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain("{$id} is on main already (".substr($merge, 0, 7).'): done')
        ->not->toContain('gates and finish.check pass')
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'done'])
        ->and($code->sandbox->read($id)['work']['merge'])->toBe($merge)
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'landed', 'merge' => $merge]))
        ->and(is_dir($code->worktree($id)))->toBeFalse()
        ->and($code->lease())->toBeNull()
        ->and($head)->not->toBe($merge);
});

it('fast-forwards the main checkout on a board without a remote, and waits while it has uncommitted changes', function () {
    $code = $this->code;
    $code->sandbox->git('remote', 'remove', 'origin');
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    file_put_contents($code->root().'/app.php', "<?php\n\nreturn 'local';\n");

    $dirty = $code->kanban(['finish', $id]);
    $code->sandbox->git('checkout', '-q', '--', 'app.php');
    $merged = $code->kanban(['finish', $id]);

    $sha = trim($code->sandbox->git('rev-parse', 'HEAD'));
    expect($dirty->getExitCode())->toBe(11)
        ->and($dirty->getErrorOutput())->toContain('main checkout not moved: it has uncommitted changes, which the merge would meet: commit or stash them')
        ->and($dirty->getOutput())->not->toContain('gates and finish.check')
        ->and($code->lease())->toBeNull()
        ->and(array_values(array_filter($code->sandbox->boardLog(), fn (string $s) => str_contains($s, ' taken '))))->toBe(["merge lease {$id} taken [owner]"])
        ->and($merged->getExitCode())->toBe(0)
        ->and($merged->getOutput())->toContain("merged {$id} into main ".substr($sha, 0, 7))
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s', 'main')))->toBe("{$id}: Add login page")
        ->and(is_file($code->root().'/login.php'))->toBeTrue();
});

it('runs the after-steps of a local fast-forward that a killed finish left undone, on a board without a remote', function (bool $during) {
    $code = $this->code;
    $code->sandbox->git('remote', 'remove', 'origin');
    $kill = 'kill -9 $(cat "'.$code->root().'/.git/laravel-house/merge/finish.pid")';
    $marker = $code->root().'/../killed';
    configureMerge($code, ['finish' => ['after' => [...($during ? ["[ -f {$marker} ] || { touch {$marker}; {$kill}; }"] : []), 'echo migrated > after.txt']]]);
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $hooks = Sandbox::tmp();
    file_put_contents("{$hooks}/post-merge", "#!/bin/sh\n".($during ? '' : $kill)."\n");
    chmod("{$hooks}/post-merge", 0755);
    $code->sandbox->git('config', 'core.hooksPath', $hooks);

    try {
        $code->kanban(['finish', $id]);
    } catch (ProcessSignaledException) {
    }
    $code->sandbox->git('config', '--unset', 'core.hooksPath');
    $state = $code->mergeState();
    $after = is_file($code->root().'/after.txt');
    $resumed = $code->kanban(['finish']);

    expect($state['phase'])->toBe('pushing')
        ->and($after)->toBeFalse()
        ->and($resumed->getExitCode())->toBe(0, $resumed->getOutput().$resumed->getErrorOutput())
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and(is_file($code->root().'/after.txt'))->toBeTrue();
})->with(['at the fast-forward' => [false], 'during the after-steps' => [true]]);

it('tears down a done card whose clone is still here, a retitled one too', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $branch = $code->sandbox->read($id)['work']['branch'];
    $code->sandbox->git('fetch', '-q', $code->worktree($id), "{$branch}:{$branch}");
    $code->sandbox->git('merge', '-q', '--no-ff', '-m', 'merged elsewhere', $branch);
    $merge = pushMain($code, 'other.txt', "other\n");
    $wt = $code->worktree($id);
    $card = $code->sandbox->read($id);
    $card['stage'] = 'done';
    $card['title'] = 'Add the login page';
    $card['claim'] = null;
    $card['work'] = ['branch' => $branch, 'base' => $card['work']['base'], 'merge' => $merge, 'started' => $card['work']['started'], 'finished' => $card['updated']];
    file_put_contents(glob($code->root().'/docs/kanban/*/'.$id.'.json')[0], json_encode($card));
    $code->sandbox->boardGit('commit', '-q', '-am', 'done elsewhere');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain('removed worktree .claude/worktrees/'.basename($wt))->toContain("deleted branch {$branch}")
        ->and(is_dir($wt))->toBeFalse()
        ->and($code->ok(['finish', $id]))->toBe("{$id} is done; nothing of it is left here\n");
});

it('keeps a done card\'s clone that holds uncommitted changes to tracked files, untracked files beside them or not', function (bool $untracked) {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $branch = $code->sandbox->read($id)['work']['branch'];
    $code->sandbox->git('fetch', '-q', $code->worktree($id), "{$branch}:{$branch}");
    $code->sandbox->git('merge', '-q', '--no-ff', '-m', 'merged elsewhere', $branch);
    $merge = pushMain($code, 'other.txt', "other\n");
    $wt = $code->worktree($id);
    file_put_contents($wt.'/login.php', "<?php // not committed\n");
    $untracked && file_put_contents($wt.'/screenshot.png', "png\n");
    $card = $code->sandbox->read($id);
    $card['stage'] = 'done';
    $card['claim'] = null;
    $card['work'] = ['branch' => $branch, 'base' => $card['work']['base'], 'merge' => $merge, 'started' => $card['work']['started'], 'finished' => $card['updated']];
    file_put_contents(glob($code->root().'/docs/kanban/*/'.$id.'.json')[0], json_encode($card));
    $code->sandbox->boardGit('commit', '-q', '-am', 'done elsewhere');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(10)
        ->and($run->getErrorOutput())->toContain('has uncommitted changes')
        ->and(file_get_contents($wt.'/login.php'))->toBe("<?php // not committed\n");
})->with(['tracked changes only' => false, 'and untracked files' => true]);

it('counts a merge that fails unexpectedly: the lease goes back each time, and the third failure blocks the card', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    $code->kanban(['finish', $id]);
    $code->ok(['finish', $id, '--abort']);
    touch($code->mergeClone().'/.git/index.lock');

    $runs = array_map(fn () => $code->kanban(['finish', $id]), [1, 2, 3]);

    expect(array_map(fn ($r) => $r->getExitCode(), $runs))->toBe([1, 1, 1])
        ->and($runs[0]->getErrorOutput())->toContain("{$id}: merge failed: GitFailed: git reset -q --hard in the merge clone failed")
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id)['blocked'])->toStartWith('merge failed 3×: ');
});

it("merges again on the new main when origin's main moved during the merge, replaying the merger's fix", function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->commit($id, 'feature.php', "<?php\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    // the merger's fix, staged and applied
    $code->gitIn($code->mergeClone(), 'rm', '-q', 'RED');
    $code->gitIn($code->mergeClone(), 'commit', '-q', '-m', 'Drop the red marker');
    $code->ok(['merged', $id, 'fixed', '--note=The marker was the card\'s own test file'], cwd: $code->mergeClone());
    $code->ok(['apply', $id]);
    expect($code->mergeState())->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1]);
    // what a cherry-pick of several commits leaves when it stops: no reset or checkout ends it
    mkdir($code->mergeClone().'/.git/sequencer');
    file_put_contents($code->mergeClone().'/.git/sequencer/todo', 'pick '.trim($code->gitIn($code->mergeClone(), 'rev-parse', 'HEAD'))." Drop the red marker\n");
    $side = $this->origin->clone('side');
    Origin::racingPush($code->sandbox, "git -C {$side->root} commit -q --allow-empty -m 'Side change' && git -C {$side->root} push -q origin HEAD:main", ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: main moved on the remote; merging again on the new main (round 2)")
        ->and($run->getOutput())->not->toContain('do not apply')
        ->and(array_slice(explode("\n", $code->gitIn($this->origin->path, 'log', '--first-parent', '--format=%s', 'main')), 0, 3))
        ->toBe(['Drop the red marker', "{$id}: Breaks the suite", 'Side change'])
        ->and(is_file($code->root().'/RED'))->toBeFalse()
        ->and(is_file($code->root().'/feature.php'))->toBeTrue()
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it("keeps its merger's fix and the lease when the remote fails as it merges again on the new main", function () {
    $code = $this->code;
    $id = mergerFixed($code);
    $side = $this->origin->clone('side');
    Origin::racingPush($code->sandbox, "git -C {$side->root} commit -q --allow-empty -m 'Side change' && git -C {$side->root} push -q origin HEAD:main"
        ." && git -C {$code->root()} remote set-url origin {$code->root()}/../no-such-origin", ref: 'refs/heads/main');

    $failed = $code->kanban(['finish', $id]);
    $state = $code->mergeState();
    $lease = $code->lease();
    $attempts = json_decode((string) @file_get_contents($code->root().'/.git/laravel-house/merge-attempts.json'), true) ?? [];
    $code->sandbox->git('remote', 'set-url', 'origin', $this->origin->path);
    $run = $code->kanban(['finish', $id]);

    expect($failed->getExitCode())->toBe(9, $failed->getOutput().$failed->getErrorOutput())
        ->and($state)->toMatchArray(['phase' => 'merging', 'round' => 2])
        ->and($state['replay'] ?? null)->toBeArray()
        ->and($lease)->toMatchArray(['card' => $id])
        ->and($attempts)->not->toHaveKey($id)
        ->and($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->not->toContain('do not apply')
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it("gives up after three rounds of origin's main moving, the card still queued", function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $side = $this->origin->clone('side');
    Origin::racingPush($code->sandbox, "git -C {$side->root} pull -q --no-rebase origin main && git -C {$side->root} commit -q --allow-empty -m side && git -C {$side->root} push -q origin HEAD:main", every: true, ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(9)
        ->and($run->getErrorOutput())->toContain("{$id}: main moved on the remote, 3 rounds running; it stays queued")
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id)['stage'])->toBe('review')
        ->and($this->origin->log('main')[0])->toBe('side');
});

it("merges nothing once following origin's main brought a finish.check that names no suite", function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    // another machine merged it: this checkout is still on the config before
    configureMerge($code, ['finish' => ['check' => []]]);
    $emptied = trim($code->sandbox->git('rev-parse', 'main'));
    $code->sandbox->git('reset', '-q', '--hard', 'HEAD~1');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(3, $run->getOutput().$run->getErrorOutput())
        ->and($run->getErrorOutput())->toContain('finish.check names no suite: no card merges until it does')
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($emptied)
        ->and($code->sandbox->read($id)['stage'])->toBe('review')
        ->and($code->sandbox->read($id)['blocked'])->toBeNull()
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull();
});

it('takes a push that failed but reached origin as done', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    Origin::racingPush($code->sandbox, 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; exit 1', ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($this->origin->log('main')[0])->toBe("{$id}: Add login page");
});

it('gives the lease back when a push failed and origin does not hold it, and blocks the card after the third', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $hooks = Origin::racingPush($code->sandbox, 'exit 1', every: true, ref: 'refs/heads/main');

    $failed = $code->kanban(['finish', $id]);
    $lease = $code->lease();
    $state = $code->mergeState();
    $more = array_map(fn () => $code->kanban(['finish', $id]), [2, 3]);

    expect($failed->getExitCode())->toBe(9)
        ->and($failed->getErrorOutput())->toContain("{$id}: main: push failed: ")->toContain('; the merge lease goes back, the card stays queued')
        ->and($lease)->toBeNull()
        ->and($state)->toBeNull()
        ->and(array_map(fn ($r) => $r->getExitCode(), $more))->toBe([9, 9])
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'review'])
        ->and($code->sandbox->read($id)['blocked'])->toStartWith('merge failed 3×: main: push failed: ')
        ->and($this->origin->log('main')[0])->not->toContain($id);
});

it('keeps a push that failed while origin cannot tell whether it landed, with its lease, and settles it next time', function (bool $lands) {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    Origin::racingPush($code->sandbox, ($lands ? 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; ' : '')
        .'git -C "'.$code->root().'" remote set-url origin "'.$code->root().'/../no-such-origin"; exit 1', ref: 'refs/heads/main');

    $failed = $code->kanban(['finish', $id]);
    $state = $code->mergeState();
    $lease = $code->lease();
    $attempts = json_decode((string) @file_get_contents($code->root().'/.git/laravel-house/merge-attempts.json'), true) ?? [];
    $code->sandbox->git('remote', 'set-url', 'origin', $this->origin->path);
    $run = $code->kanban(['finish', $id]);

    expect($failed->getExitCode())->toBe(9, $failed->getOutput().$failed->getErrorOutput())
        ->and($state)->toMatchArray(['phase' => 'pushing'])
        ->and($state['merged'] ?? null)->toBeString()
        ->and($lease)->toMatchArray(['card' => $id])
        ->and($attempts)->not->toHaveKey($id)
        ->and($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and(str_contains($run->getOutput(), "{$id}: its push did not land; merging again on the new main (round 2)"))->toBe(! $lands)
        ->and(array_values(array_filter($this->origin->log('main'), fn (string $s) => $s === "{$id}: Add login page")))->toHaveCount(1)
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($code->sandbox->read($id)['blocked'] ?? null)->toBeNull()
        ->and($code->lease())->toBeNull();
})->with(['it landed' => [true], 'it did not land' => [false]]);

it('finishes a merge killed after its push landed', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    // the push lands, then the finish dies before it records it
    Origin::racingPush($code->sandbox, 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; kill -9 $(cat "'.$code->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');

    try {
        $code->kanban(['finish', $id]);
        $killed = false;
    } catch (ProcessSignaledException) {
        $killed = true;
    }
    $landed = $code->gitIn($this->origin->path, 'log', '-1', '--format=%s', 'main');
    $state = $code->mergeState();
    $resumed = $code->kanban(['finish']);

    expect($killed)->toBeTrue()
        ->and(trim($landed))->toBe("{$id}: Add login page")
        ->and($state['phase'])->toBe('pushing')
        ->and($resumed->getExitCode())->toBe(0, $resumed->getErrorOutput())
        ->and($resumed->getOutput())->toContain("merged {$id} into main")
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($code->lease())->toBeNull();
});

it('settles a push in flight while finish.check names no suite: done once it landed, else the merge let go', function (bool $lands) {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    Origin::racingPush($code->sandbox, ($lands ? 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; ' : '')
        .'kill -9 $(cat "'.$code->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');
    try {
        $code->kanban(['finish', $id]);
    } catch (ProcessSignaledException) {
    }
    expect($code->mergeState()['phase'])->toBe('pushing');
    $code->configure(['finish' => ['check' => []]]);

    $settled = $code->kanban(['finish', $id]);
    $abort = $code->kanban(['finish', $id, '--abort']);

    expect($settled->getExitCode())->toBe($lands ? 0 : 3, $settled->getOutput().$settled->getErrorOutput())
        ->and($code->sandbox->read($id)['stage'])->toBe($lands ? 'done' : 'review')
        ->and($code->sandbox->read($id)['blocked'] ?? null)->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->lease())->toBeNull()
        ->and($abort->getOutput())->toContain('no merge runs here');
    $lands || expect($settled->getErrorOutput())->toContain('finish.check names no suite');
})->with(['it landed' => [true], 'it did not land' => [false]]);

it('records a push that landed after the card left review, and tears nothing of it down', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $kanban = PHP_BINARY.' '.$code->root().'/vendor/bin/kanban';
    Origin::racingPush($code->sandbox, "cd {$code->root()} && KANBAN_SYNC=off KANBAN_SESSION= XDEBUG_MODE=off {$kanban} move {$id} doing --reason='One more thing' >/dev/null 2>&1", ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    $sha = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    expect($run->getExitCode())->toBe(10)
        ->and($run->getErrorOutput())->toContain("{$id} reached main but left review: it is doing")
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'landed', 'merge' => $sha]))
        ->and(is_dir($code->worktree($id)))->toBeTrue()
        ->and($code->lease())->toBeNull();
});

it('keeps what the command printed on main alone with the main red it records', function () {
    $code = $this->code;
    $check = 'if [ -e RED ]; then echo "RED is on main"; exit 1; fi';
    configureMerge($code, ['finish' => ['after' => [], 'check' => [$check]]]);
    $id = $code->started('Tag notes');
    $code->commit($id, 'tags.php', "<?php\n");
    $code->approve($id);
    pushMain($code, 'RED', "red\n");

    expect($code->kanban(['finish', $id])->getExitCode())->toBe(13)
        ->and(merges($code, $id)[0])->toMatchArray(['result' => 'main', 'command' => $check])
        ->and(merges($code, $id)[0]['note'])->toContain('RED is on main')
        ->and($code->ok(['status']))->toContain("(`vendor/bin/kanban show {$id} --log=5` has its output)");
});

it('calls main red when the failing command fails on the base alone, holds the queue until main moves and files no card', function () {
    $code = $this->code;
    $first = $code->started('Tag notes');
    $code->commit($first, 'tags.php', "<?php\n");
    $code->approve($first, at: '2026-01-01T00:00:00.000+00:00');
    $second = $code->started('Archive notes');
    $code->commit($second, 'archive.php', "<?php\n");
    $code->approve($second, at: '2026-01-02T00:00:00.000+00:00');
    $base = pushMain($code, 'RED', "red\n");
    $cards = count(glob($code->root().'/docs/kanban/work/*.json'));

    $run = $code->kanban(['finish', $first]);
    $pins = trim($code->sandbox->git('for-each-ref', "refs/merge-queue/{$first}/"));
    $held = $code->kanban(['finish', $second]);

    expect($run->getExitCode())->toBe(13)
        ->and($run->getOutput())->toContain("{$first}: `test ! -e RED` fails on main alone too (".substr($base, 0, 7).')')
        ->and(merges($code, $first))->sequence(fn ($e) => $e->toMatchArray(['result' => 'main', 'command' => 'test ! -e RED', 'base' => $base])->not->toHaveKey('red'))
        ->and(count(glob($code->root().'/docs/kanban/work/*.json')))->toBe($cards)
        ->and($pins)->toBe('')
        ->and($code->lease())->toBeNull()
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($base)
        ->and($held->getExitCode())->toBe(11)
        ->and($held->getErrorOutput())->toContain("{$second} waits for main to be fixed (`test ! -e RED` fails on main at ".substr($base, 0, 7).')')
        ->and(merges($code, $second))->toBe([]);

    $code->sandbox->git('rm', '-q', 'RED');
    $code->sandbox->git('commit', '-q', '-m', 'Fix main by hand');
    $code->ok(['publish']);
    expect($code->kanban(['finish', $first])->getExitCode())->toBe(0)
        ->and($code->kanban(['finish', $second])->getExitCode())->toBe(0);
});

it('holds the queue on a finish.check command that timed out, ends it, reruns nothing on main, and lifts the hold once its card is blocked', function () {
    $code = $this->code;
    $marker = '310.'.random_int(100000, 999999);
    $log = $code->root().'/../suite-runs';
    $check = "echo run >> {$log}; if [ -e SLOW ]; then sleep {$marker}; fi";
    configureMerge($code, ['finish' => ['after' => [], 'check' => [['run' => $check, 'timeout' => 2]]]]);
    $first = $code->started('Tag notes');
    $head = $code->commit($first, 'SLOW', "slow\n");
    $code->approve($first, at: '2026-01-01T00:00:00.000+00:00');
    $second = $code->started('Archive notes');
    $code->commit($second, 'archive.php', "<?php\n");
    $code->approve($second, at: '2026-01-02T00:00:00.000+00:00');
    $base = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    $cards = count(glob($code->root().'/docs/kanban/work/*.json'));

    $run = $code->kanban(['finish', $first]);
    $left = survivors($marker);
    $held = $code->kanban(['finish', $second]);
    $hold = "waits for a timed-out check (`{$check}` ran past 2 s merging {$first})";

    expect($run->getExitCode())->toBe(13, $run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$first}: `{$check}` timed out after 2 s; the merge queue holds until main moves or {$first} is blocked")
        ->and($left)->toBe([])
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe(['run'])
        ->and(merges($code, $first))->toHaveCount(1)
        ->and(merges($code, $first)[0])->toMatchArray(['result' => 'timeout', 'step' => 'suite', 'command' => $check, 'seconds' => 2, 'base' => $base, 'head' => $head])
        ->and(count(glob($code->root().'/docs/kanban/work/*.json')))->toBe($cards)
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($first)['stage'])->toBe('review')
        ->and($code->sandbox->read($first)['work']['approved']['head'])->toBe($head)
        ->and($held->getExitCode())->toBe(11)
        ->and($held->getErrorOutput())->toContain("{$second} {$hold}")
        ->and($code->ok(['status']))->toContain($hold)
        ->and($code->ok(['morning']))->toContain("`{$check}` timed out after 2 s at ".substr($base, 0, 7).", merging {$first}");

    $code->ok(['set', $first, 'blocked=its tests hang']);
    expect($code->kanban(['finish', $second])->getExitCode())->toBe(0)
        ->and($code->ok(['status']))->not->toContain('timed-out')->not->toContain('a check timed out');
});

it('lifts the hold of a timed-out check once main moves', function () {
    $code = $this->code;
    $check = 'if [ -e SLOW ]; then sleep 311.'.random_int(100000, 999999).'; fi';
    configureMerge($code, ['finish' => ['after' => [], 'check' => [['run' => $check, 'timeout' => 1]]]]);
    $id = $code->started('Tag notes');
    $code->commit($id, 'SLOW', "slow\n");
    $code->approve($id);

    expect($code->kanban(['finish', $id])->getExitCode())->toBe(13)
        ->and($code->ok(['status']))->toContain('waits for a timed-out check');

    pushMain($code, 'faster.txt', "faster\n");
    expect($code->ok(['status']))->not->toContain('timed-out')->not->toContain('a check timed out');
});

it('holds the queue on a gate that timed out in the merge stack, as on a finish.check command', function () {
    $code = $this->code;
    $marker = '312.'.random_int(100000, 999999);
    $gate = "test \"\$FAKE_DOCKER_EXEC\" = 1 && sleep {$marker}";
    configureMerge($code, ['gates' => ['report' => [['run' => $gate, 'timeout' => 1]]]]);
    $id = $code->started('Tag notes');
    $code->commit($id, 'tags.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(13, $run->getErrorOutput())
        ->and(survivors($marker))->toBe([])
        ->and(array_column(merges($code, $id), 'result'))->toBe(['timeout'])
        ->and(merges($code, $id)[0])->toMatchArray(['step' => 'gate', 'command' => $gate, 'seconds' => 1])
        ->and($code->lease())->toBeNull()
        ->and($code->sandbox->read($id)['stage'])->toBe('review');
});

it('sends a card with leftover conflict markers back to its worker through the merge', function () {
    $code = $this->code;
    $id = $code->started('Notes');
    $code->commit($id, 'notes.md', "<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> main\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    $card = $code->sandbox->read($id);
    expect($run->getExitCode())->toBe(13)
        ->and($run->getOutput())->toContain("{$id} review→doing: leftover conflict markers: notes.md:1")
        ->and($card['stage'])->toBe('doing')
        ->and($card['work']['approved'])->toBeNull()
        ->and(merges($code, $id))->sequence(fn ($e) => $e->toMatchArray(['result' => 'back', 'note' => 'leftover conflict markers: notes.md:1, notes.md:3, notes.md:5']))
        ->and(array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'stage' && ($e['via'] ?? null) === 'merge')))->toHaveCount(1)
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull();
});

it('sends a merge that stays red after three merger rounds back to the worker', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    $code->kanban(['finish', $id]);
    file_put_contents($code->root().'/.git/laravel-house/merge.json', json_encode(['phase' => 'checks', 'merger_rounds' => 3] + $code->mergeState()));

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(13)
        ->and($run->getOutput())->toContain("{$id} review→doing: the merge stays red after 3 merger rounds: `test ! -e RED`")
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and(array_column(merges($code, $id), 'result'))->toBe(['red', 'red', 'back'])
        ->and($code->lease())->toBeNull();
});

it('reuses the merge clone and its stack, installing only a lockfile that changed since its last install', function () {
    $code = $this->code;
    $log = $code->root().'/../installs.log';
    configureMerge($code, ['finish' => ['install' => ['composer.lock' => "echo \"install \$FAKE_DOCKER_EXEC\" >> {$log}"]]]);
    $first = $code->started('Bump deps');
    $code->commit($first, 'composer.lock', "{}\n");
    $code->approve($first);
    $code->ok(['finish', $first]);
    @mkdir($code->mergeClone().'/vendor', 0775, true);
    file_put_contents($code->mergeClone().'/vendor/kept.txt', "kept\n");
    $ups = fn () => count(array_filter($code->calls(), fn ($call) => str_contains($call, '-p '.mergeProject($code).' up ')));
    $before = $ups();
    $second = $code->started('Add login page');
    $code->commit($second, 'login.php', "<?php\n");
    $code->approve($second);

    $code->ok(['finish', $second]);

    // the merge stack's install, then the main checkout's after the push
    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['install 1', 'install '])
        ->and(file_get_contents($code->mergeClone().'/vendor/kept.txt'))->toBe("kept\n")
        ->and($ups())->toBe($before)
        ->and($code->sandbox->read($second)['stage'])->toBe('done');
});

it('merges in a merge clone someone left on main', function () {
    $code = $this->code;
    $first = $code->started('Add login page');
    $code->commit($first, 'login.php', "<?php\n");
    $code->approve($first);
    $code->ok(['finish', $first]);
    $code->gitIn($code->mergeClone(), 'switch', '-q', 'main');
    $second = $code->started('Archive notes');
    $code->commit($second, 'archive.php', "<?php\n");
    $code->approve($second);

    $run = $code->kanban(['finish', $second]);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($code->sandbox->read($second)['stage'])->toBe('done');
});

it('installs a lockfile again after an install of another content failed in the merge clone', function () {
    $code = $this->code;
    $log = $code->root().'/../installs.log';
    $broken = $code->root().'/../registry-down';
    configureMerge($code, ['finish' => ['install' => ['composer.lock' => "test ! -e {$broken} && echo \"install \$FAKE_DOCKER_EXEC\" >> {$log}"]]]);
    $first = $code->started('Bump deps');
    $code->commit($first, 'composer.lock', "{}\n");
    $code->approve($first);
    $code->ok(['finish', $first]);
    // an install that fails part way leaves the merge clone's vendor/ as neither lockfile has it
    touch($broken);
    $failing = $code->started('Bump deps again');
    $code->commit($failing, 'composer.lock', "{\"b\": 1}\n");
    $code->approve($failing);
    expect($code->kanban(['finish', $failing])->getExitCode())->toBe(12)
        ->and($code->mergeState()['failure']['step'])->toBe('install');
    $code->ok(['finish', $failing, '--abort']);
    $code->sandbox->ok(['set', $failing, 'blocked=waits on the registry']);
    unlink($broken);
    $second = $code->started('Add login page');
    $code->commit($second, 'login.php', "<?php\n");
    $code->approve($second);

    $code->ok(['finish', $second]);

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['install 1', 'install ', 'install 1'])
        ->and($code->sandbox->read($second)['stage'])->toBe('done');
});

it('runs nothing a merger could plant in the merge clone\'s git, and resets a merge a crash left in it', function () {
    $code = $this->code;
    $first = $code->started('Add login page');
    $code->commit($first, 'login.php', "<?php\n");
    $code->approve($first);
    $code->ok(['finish', $first]);
    $clone = $code->mergeClone();
    $ran = $code->root().'/../ran';
    $marker = "sh -c 'touch {$ran}-\${FAKE_DOCKER_EXEC:-host}'";
    file_put_contents($clone.'/.git/info/attributes', "* merge=planted filter=planted\n");
    foreach (['merge.planted.driver' => $marker, 'filter.planted.smudge' => $marker, 'filter.planted.clean' => $marker,
        'core.fsmonitor' => $marker, 'gpg.program' => $marker, 'commit.gpgsign' => 'true'] as $key => $value) {
        $code->gitIn($clone, 'config', $key, $value);
    }
    @mkdir($clone.'/.git/hooks', 0775, true);
    file_put_contents($clone.'/.git/hooks/post-checkout', "#!/bin/sh\n{$marker}\n");
    chmod($clone.'/.git/hooks/post-checkout', 0755);
    file_put_contents($clone.'/app.php', "<?php\n\nreturn 'crash';\n");
    $second = $code->started('Conflict');
    $code->commit($second, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($second);
    pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");

    $run = $code->kanban(['finish', $second]);

    expect($run->getExitCode())->toBe(12, $run->getErrorOutput())
        ->and(glob($ran.'-*'))->toBe([])
        ->and(is_file($clone.'/.git/info/attributes'))->toBeFalse()
        ->and(is_dir($clone.'/.git/hooks'))->toBeFalse()
        ->and($code->mergeState()['conflicts'])->toBe(['app.php'])
        ->and(file_get_contents($clone.'/app.php'))->toContain("return 'branch';")->not->toContain('crash');
});

it('brings the merge stack up outside stack.max_stacks and the machine-load check', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    configureMerge($code, ['stack' => ['max_stacks' => 1, 'max_load_ratio' => 0.001]]);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('stops at the next step once its beater found the lease lost, pushing nothing', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    // what the beater writes when another machine took the lease
    $state = $code->root().'/.git/laravel-house/merge.json';
    configureMerge($code, ['gates' => ['report' => ['php -r '.escapeshellarg('$f = "'.$state.'"; file_put_contents($f, json_encode(["lost" => "merge lease lost: now held elsewhere"] + json_decode(file_get_contents($f), true)));')]]]);
    $origin = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    // a merger of the card still at work, as `kanban run` launched it
    $session = 'a1b2c3d4-0000-4000-8000-000000000001';
    $merger = new Process(['setsid', 'sh', '-c', 'sleep 300; :', 'sh', $session]);
    $merger->start();
    @mkdir($code->root().'/.git/laravel-house/runs', 0775, true);
    file_put_contents($code->root()."/.git/laravel-house/runs/{$session}.pid", json_encode(['pid' => $merger->getPid(), 'card' => $id, 'type' => 'kanban-merger', 'stage' => 'review']));

    try {
        $run = $code->kanban(['finish', $id]);
        $alive = $merger->isRunning();
    } finally {
        $merger->stop(0);
    }

    expect($run->getExitCode())->toBe(8, $run->getErrorOutput())
        ->and($run->getErrorOutput())->toContain('merge lease lost: now held elsewhere')
        ->and($alive)->toBeFalse()
        ->and($code->mergeState())->toBeNull()
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($origin)
        ->and($code->sandbox->read($id)['stage'])->toBe('review');
});

it('exits 7 when a finish.check command is not found in the app container, the card still queued', function () {
    $code = $this->code;
    configureMerge($code, ['finish' => ['check' => ['no-such-suite --all']]]);
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(7)
        ->and($run->getErrorOutput())->toContain('finish.check: `no-such-suite --all` is not found in the app container (exit 127)')
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id)['stage'])->toBe('review');
});

it('says a finish.check command is not found on this machine when the agents\' shell is the host', function () {
    $code = $this->code;
    configureMerge($code, ['gates' => ['report' => []], 'agents' => ['shell' => 'host'], 'finish' => ['check' => ['no-such-suite --all']]]);
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(7)
        ->and($run->getErrorOutput())->toContain('finish.check: `no-such-suite --all` is not found on this machine (exit 127)')
        ->and($run->getErrorOutput())->not->toContain('app container');
});

it('pushes only the head its checks ran on: a commit made in the merge clone meanwhile is merged again without it', function () {
    $code = $this->code;
    $marker = $code->root().'/../planted';
    configureMerge($code, ['gates' => ['report' => ["[ -f {$marker} ] || { touch {$marker}; echo planted > planted.txt; git add planted.txt; git -c user.name=x -c user.email=x@example.com commit -q -m 'Planted by a gate'; }"]]]);
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: the merge clone holds commits no check ran on; merging again on the new main (round 2)")
        ->and($code->gitIn($this->origin->path, 'log', '--format=%s', 'main'))->not->toContain('Planted by a gate')
        ->and(is_file($code->root().'/planted.txt'))->toBeFalse()
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it("replays its merger's checked fix when a commit made in the merge clone during the checks merges it again", function () {
    $code = $this->code;
    $id = mergerFixed($code);
    $marker = $code->root().'/../planted';
    configureMerge($code, ['gates' => ['report' => ["[ -f {$marker} ] || { touch {$marker}; echo planted > planted.txt; git add planted.txt; git -c user.name=x -c user.email=x@example.com commit -q -m 'Planted by a gate'; }"]]]);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: the merge clone holds commits no check ran on; merging again on the new main (round 2)")
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and($code->gitIn($this->origin->path, 'log', '--format=%s', 'main'))->not->toContain('Planted by a gate')
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it("checks a merger's result as committed: a file git does not track is refused, and one left after it is cleaned away", function () {
    $code = $this->code;
    configureMerge($code, ['finish' => ['check' => ['test ! -e RED || test -e WAIVED']]]);
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $clone = $code->mergeClone();
    $code->gitIn($clone, 'commit', '-q', '--allow-empty', '-m', 'Waive it');
    file_put_contents($clone.'/WAIVED', "waived\n");

    $untracked = $code->kanban(['merged', $id, 'fixed', '--note=Waived'], cwd: $clone);
    unlink($clone.'/WAIVED');
    $code->ok(['merged', $id, 'fixed', '--note=Waived'], cwd: $clone);
    $code->ok(['apply', $id]);
    // the merger, still at work, leaves it again
    file_put_contents($clone.'/WAIVED', "waived\n");
    $origin = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    $run = $code->kanban(['finish', $id]);

    expect($untracked->getExitCode())->toBe(3)
        ->and($untracked->getErrorOutput())->toContain('?? WAIVED')
        ->and($run->getExitCode())->toBe(12, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: red: `test ! -e RED || test -e WAIVED`; the merger's turn")
        ->and(is_file($clone.'/WAIVED'))->toBeFalse()
        ->and(trim($code->gitIn($this->origin->path, 'rev-parse', 'main')))->toBe($origin);
});

it('replays a conflict resolution the merger made (rerere) when main moved, and forgets one the merger gate refuses', function (bool $skips, int $exit) {
    $code = $this->code;
    $id = $code->started('Conflict');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $clone = $code->mergeClone();
    // git records the resolution as committed; a merger that then amends it leaves that record behind
    file_put_contents($clone.'/app.php', $skips ? "<?php\n\nreturn fn (\$t) => \$t->skip();\n" : "<?php\n\nreturn 'both';\n");
    $code->gitIn($clone, 'add', 'app.php');
    $code->gitIn($clone, 'commit', '-q', '--no-edit');
    file_put_contents($clone.'/app.php', "<?php\n\nreturn 'both';\n");
    $code->gitIn($clone, 'commit', '-q', '-a', '--amend', '--no-edit');
    $code->ok(['merged', $id, 'resolved', '--note=Both sides'], cwd: $clone);
    $code->ok(['apply', $id]);
    $side = $this->origin->clone('side');
    Origin::racingPush($code->sandbox, "git -C {$side->root} commit -q --allow-empty -m 'Side change' && git -C {$side->root} push -q origin HEAD:main", ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe($exit, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: main moved on the remote; merging again on the new main (round 2)");
    if ($skips) {
        expect($run->getOutput())->toContain("{$id}: the resolution git remembered for app.php would add a skipped test in app.php: forgotten, the merger resolves it")
            ->and($code->mergeState())->toMatchArray(['phase' => 'conflict', 'round' => 2])
            ->and($this->origin->log('main')[0])->toBe('Side change');
    } else {
        expect($run->getOutput())->toContain("{$id}: conflicts in app.php resolved as before (rerere)")
            ->and(trim((string) $code->gitIn($this->origin->path, 'show', 'main:app.php')))->toBe("<?php\n\nreturn 'both';")
            ->and($code->sandbox->read($id)['stage'])->toBe('done');
    }
})->with(['as resolved' => [false, 0], 'skipping a test' => [true, 12]]);

it('replays the fixes of its merger after a conflict that rerere resolves on the new main', function () {
    $code = $this->code;
    $id = $code->started('Conflict');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $clone = $code->mergeClone();
    file_put_contents($clone.'/app.php', "<?php\n\nreturn 'both';\n");
    $code->gitIn($clone, 'add', 'app.php');
    $code->gitIn($clone, 'commit', '-q', '--no-edit');
    $code->ok(['merged', $id, 'resolved', '--note=Both sides'], cwd: $clone);
    $code->ok(['apply', $id]);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $code->gitIn($clone, 'rm', '-q', 'RED');
    $code->gitIn($clone, 'commit', '-q', '-m', 'Drop the red marker');
    $code->ok(['merged', $id, 'fixed', '--note=The marker was the card\'s own test file'], cwd: $clone);
    $code->ok(['apply', $id]);
    $side = $this->origin->clone('side');
    Origin::racingPush($code->sandbox, "git -C {$side->root} commit -q --allow-empty -m 'Side change' && git -C {$side->root} push -q origin HEAD:main", ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: conflicts in app.php resolved as before (rerere)")
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and(is_file($code->root().'/RED'))->toBeFalse()
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it("replays its merger's fixes on the new main when one of them is on main already", function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $clone = $code->mergeClone();
    file_put_contents($clone.'/shared.txt', "shared\n");
    $code->gitIn($clone, 'add', 'shared.txt');
    $code->gitIn($clone, 'commit', '-q', '-m', 'Add the shared file');
    $code->gitIn($clone, 'rm', '-q', 'RED');
    $code->gitIn($clone, 'commit', '-q', '-m', 'Drop the red marker');
    $code->ok(['merged', $id, 'fixed', '--note=The marker was the card\'s own test file'], cwd: $clone);
    $code->ok(['apply', $id]);
    $side = $this->origin->clone('side');
    file_put_contents($side->root.'/shared.txt', "shared\n");
    Origin::racingPush($code->sandbox, "git -C {$side->root} add shared.txt && git -C {$side->root} commit -q -m 'Side change' && git -C {$side->root} push -q origin HEAD:main", ref: 'refs/heads/main');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->not->toContain('do not apply')
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and(is_file($code->root().'/RED'))->toBeFalse()
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('keeps the merge and its lease when the remote fails as it resumes, and goes on with it next time', function () {
    $code = $this->code;
    $code->defaults['KANBAN_SYNC'] = 'on';
    $code->ok(['sync']);
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $code->gitIn($code->mergeClone(), 'rm', '-q', 'RED');
    $code->gitIn($code->mergeClone(), 'commit', '-q', '-m', 'Drop the red marker');
    $code->ok(['merged', $id, 'fixed', '--note=The marker was the card\'s own test file'], cwd: $code->mergeClone());
    $code->ok(['apply', $id]);
    $code->sandbox->git('remote', 'set-url', 'origin', $code->root().'/../no-such-origin');

    $failed = $code->kanban(['finish', $id]);
    $state = $code->mergeState();
    $code->sandbox->git('remote', 'set-url', 'origin', $this->origin->path);
    $run = $code->kanban(['finish', $id]);

    expect($failed->getExitCode())->toBe(9)
        ->and($state)->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1])
        ->and($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('records the approved head itself as the merge when main was fast-forwarded to it by hand', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $head = $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $branch = $code->sandbox->read($id)['work']['branch'];
    $code->sandbox->git('fetch', '-q', $code->worktree($id), "{$branch}:{$branch}");
    $code->sandbox->git('merge', '-q', '--ff-only', $branch);
    pushMain($code, 'other.txt', "other\n");

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id} is on main already (".substr($head, 0, 7).'): done')
        ->and($code->sandbox->read($id)['work']['merge'])->toBe($head);
});

it('merges and pushes while the main checkout cannot follow, and exits 10', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $code->sandbox->git('checkout', '-q', '-b', 'elsewhere');

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(10)
        ->and($run->getErrorOutput())->toContain('main checkout not moved')
        ->and($this->origin->log('main')[0])->toBe("{$id}: Add login page")
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('starts its own beater once the beater of the merge before it is gone', function () {
    $code = $this->code;
    $runtime = $code->root().'/.git/laravel-house';
    [$gone, $released] = [$code->root().'/../beater-gone', $code->root().'/../beater-released'];
    // the merge before's beater, holding its lock as this merge takes the lease; it says when it let go
    @mkdir($runtime, 0775, true);
    $before = new Process(['sh', '-c', "flock {$runtime}/merge.beat.lock sh -c 'until [ -f {$gone} ]; do sleep 0.1; done'; touch {$released}"]);
    $before->start();
    for ($until = microtime(true) + 10; microtime(true) < $until && ! is_file($runtime.'/merge.beat.lock');) {
        usleep(50_000);
    }
    // the gate ends the beaters this merge started meanwhile, as if each gave up waiting, then lets the one before go:
    // only the checks' tick can start this merge's beater after it ([/] keeps pkill from matching the gate itself)
    configureMerge($code, ['gates' => ['report' => [
        "pkill -f -- '{$code->root()}[/]vendor/bin/kanban finish --beat='; touch {$gone}; until [ -f {$released} ]; do sleep 0.1; done",
    ]]]);
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);
    $lease = (string) $code->mergeState()['lease'];
    // its beater, beating: the lock it took names its lease
    $beats = fn (): bool => @file_get_contents($runtime.'/merge.beat.lock') === $lease;
    for ($until = microtime(true) + 10; microtime(true) < $until && ! $beats();) {
        usleep(50_000);
    }

    expect($run->getExitCode())->toBe(12, $run->getErrorOutput())
        ->and($before->isRunning())->toBeFalse()
        ->and($beats())->toBeTrue();
});

it('starts its own beater on a merge that stops on a conflict while the beater of the merge before it still ends', function () {
    $code = $this->code;
    $runtime = $code->root().'/.git/laravel-house';
    @mkdir($runtime, 0775, true);
    // the merge before's beater, holding its lock until this merge is at its merger's turn
    $before = new Process(['flock', $runtime.'/merge.beat.lock', 'sh', '-c', "until grep -q '\"phase\": *\"conflict\"' {$runtime}/merge.json 2>/dev/null; do sleep 0.1; done"]);
    $before->start();
    for ($until = microtime(true) + 10; microtime(true) < $until && ! is_file($runtime.'/merge.beat.lock');) {
        usleep(50_000);
    }
    $id = $code->started('Conflict');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");

    $run = $code->kanban(['finish', $id]);
    $lease = (string) $code->mergeState()['lease'];
    for ($until = microtime(true) + 10; microtime(true) < $until && $before->isRunning();) {
        usleep(50_000);
    }
    // its beater, beating: the lock it took names its lease
    $beats = fn (): bool => @file_get_contents($runtime.'/merge.beat.lock') === $lease;
    for ($until = microtime(true) + 10; microtime(true) < $until && ! $beats();) {
        usleep(50_000);
    }

    expect($run->getExitCode())->toBe(12, $run->getErrorOutput())
        ->and($before->isRunning())->toBeFalse()
        ->and($beats())->toBeTrue();
});

/** The merge stack gone, as after a reboot: its server ended, docker knowing no project of it. */
function mergeStackGone(CodeSandbox $code): void
{
    $file = $code->docker.'/state.json';
    $state = json_decode((string) file_get_contents($file), true);
    if (! empty($state['projects'][mergeProject($code)]['pid'])) {
        posix_kill((int) $state['projects'][mergeProject($code)]['pid'], SIGTERM);
    }
    unset($state['projects'][mergeProject($code)]);
    file_put_contents($file, json_encode($state));
}

/** A red merge whose merger's fix (the RED marker dropped) is applied: phase checks. */
function mergerFixed(CodeSandbox $code): string
{
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $code->gitIn($code->mergeClone(), 'rm', '-q', 'RED');
    $code->gitIn($code->mergeClone(), 'commit', '-q', '-m', 'Drop the red marker');
    $code->ok(['merged', $id, 'fixed', '--note=The marker was the card\'s own test file'], cwd: $code->mergeClone());
    $code->ok(['apply', $id]);

    return $id;
}

it("keeps a merger's applied result and the lease when the merge stack fails as it resumes, and checks it once the stack is back", function () {
    $code = $this->code;
    $id = mergerFixed($code);
    mergeStackGone($code);

    $failed = $code->kanban(['finish', $id], ['FAKE_DOCKER_FAIL' => 'up']);
    $state = $code->mergeState();
    $lease = $code->lease();
    $run = $code->kanban(['finish', $id]);

    expect($failed->getExitCode())->toBe(7, $failed->getOutput().$failed->getErrorOutput())
        ->and($failed->getOutput())->toContain("{$id}: the merge and its lease stay: the next `kanban finish {$id}` goes on with it")
        ->and($state)->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1])
        ->and($lease)->toMatchArray(['card' => $id])
        ->and($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($this->origin->log('main')[0])->toBe('Drop the red marker')
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('lets a merge with its merger\'s work go, uncounted, when a finish.check command is not found on main either', function () {
    $code = $this->code;
    $id = mergerFixed($code);
    configureMerge($code, ['finish' => ['check' => ['no-such-suite --all']]]);

    $runs = array_map(fn () => $code->kanban(['finish', $id]), [1, 2, 3]);

    expect(array_map(fn ($r) => $r->getExitCode(), $runs))->toBe([7, 7, 7], $runs[0]->getOutput().$runs[0]->getErrorOutput())
        ->and($runs[0]->getErrorOutput())->toContain('finish.check: `no-such-suite --all` is not found in the app container (exit 127), on main either')
        ->and($runs[0]->getOutput())->not->toContain('the merge and its lease stay')
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id)['stage'])->toBe('review')
        ->and($code->sandbox->read($id)['blocked'] ?? null)->toBeNull();
});

it('counts a merged tree whose docker files the merge stack fails on, and blocks the card at the third', function () {
    $code = $this->code;
    $id = $code->started('Change the image');
    $code->commit($id, 'Dockerfile.local', "FROM scratch\n");
    $code->approve($id);

    $runs = array_map(fn () => $code->kanban(['finish', $id], ['FAKE_DOCKER_BROKEN' => 'Dockerfile.local']), [1, 2, 3]);

    expect(array_map(fn ($r) => $r->getExitCode(), $runs))->toBe([7, 7, 7], $runs[0]->getErrorOutput())
        ->and($runs[0]->getErrorOutput())->toContain('failed to solve: Dockerfile.local')
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id)['stage'])->toBe('review')
        ->and($code->sandbox->read($id)['blocked'])->toStartWith('merge failed 3×: ');
});

it('gives the merge up and blocks the card when its stack fails three times as it resumes', function () {
    $code = $this->code;
    $id = mergerFixed($code);
    mergeStackGone($code);

    $runs = array_map(fn () => $code->kanban(['finish', $id], ['FAKE_DOCKER_FAIL' => 'up']), [1, 2, 3]);

    expect(array_map(fn ($r) => $r->getExitCode(), $runs))->toBe([7, 7, 7])
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'review'])
        ->and($code->sandbox->read($id)['blocked'])->toStartWith('merge failed 3×: ')
        ->and(trim($code->sandbox->git('for-each-ref', "refs/merge-queue/{$id}/")))->toBe('');
});

it("ends a merge its merger sent back, or one aborted, while the merge stack is gone: the right exit, the card's pins gone", function (bool $abort) {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    if (! $abort) {
        $code->ok(['merged', $id, 'back', '--note=The RED marker is the card\'s own'], cwd: $code->mergeClone());
        $code->ok(['apply', $id]);
    }
    mergeStackGone($code);

    $run = $code->kanban($abort ? ['finish', $id, '--abort'] : ['finish', $id]);

    expect($run->getExitCode())->toBe($abort ? 0 : 13, $run->getOutput().$run->getErrorOutput())
        ->and($code->lease())->toBeNull()
        ->and($code->mergeState())->toBeNull()
        ->and(trim($code->sandbox->git('for-each-ref', "refs/merge-queue/{$id}/")))->toBe('')
        ->and($code->sandbox->read($id)['stage'])->toBe($abort ? 'review' : 'doing');
})->with(['sent back' => [false], 'aborted' => [true]]);

it('brings a merge stack that went down back up before the merger works in it, leaving the conflict as it is', function () {
    $code = $this->code;
    $id = $code->started('Conflict');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    pushMain($code, 'app.php', "<?php\n\nreturn 'main';\n");
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $state = $code->mergeState();
    mergeStackGone($code);

    $run = $code->kanban(['finish', $id]);

    $docker = json_decode((string) file_get_contents($code->docker.'/state.json'), true);
    expect($run->getExitCode())->toBe(12, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain("{$id}: conflicts in app.php; the merger's turn")
        ->and($docker['projects'])->toHaveKey(mergeProject($code))
        ->and(array_diff_key($code->mergeState(), ['beat_at' => 1]))->toBe(array_diff_key($state, ['beat_at' => 1]))
        ->and(file_get_contents($code->mergeClone().'/app.php'))->toContain('<<<<<<<');
});

it('hands the merger a finish.check command the merged tree lost, which the base has, saying whether it passes there', function (bool $passes) {
    $code = $this->code;
    configureMerge($code, ['finish' => ['check' => ['./suite.sh']]]);
    file_put_contents($code->root().'/suite.sh', $passes ? "#!/bin/sh\ntest ! -e RED\n" : "#!/bin/sh\nexit 1\n");
    chmod($code->root().'/suite.sh', 0755);
    $code->sandbox->git('add', 'suite.sh');
    $code->sandbox->git('commit', '-q', '-m', 'The suite');
    $code->sandbox->git('push', '-q', 'origin', 'main');
    $id = $code->started('Drops the suite');
    $code->gitIn($code->worktree($id), 'rm', '-q', 'suite.sh');
    $code->gitIn($code->worktree($id), 'commit', '-q', '-m', 'Drop the suite');
    $code->approve($id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe(12, $run->getOutput().$run->getErrorOutput())
        ->and($code->mergeState())->toMatchArray(['phase' => 'red', 'failure' => ['step' => 'suite', 'command' => './suite.sh', 'exit' => 127,
            'tail' => $code->mergeState()['failure']['tail'], 'base_rerun' => $passes ? 'passed' : 'failed']])
        ->and($code->lease())->toMatchArray(['card' => $id]);
})->with(['it passes on main' => [true], 'it fails on main' => [false]]);

it('merges again a merge killed before its push landed, and pushes it', function () {
    $code = $this->code;
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    $origin = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    // the finish dies before its push reaches origin
    Origin::racingPush($code->sandbox, 'kill -9 $(cat "'.$code->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');

    try {
        $code->kanban(['finish', $id]);
        $killed = false;
    } catch (ProcessSignaledException) {
        $killed = true;
    }
    $state = $code->mergeState();
    $before = trim($code->gitIn($this->origin->path, 'rev-parse', 'main'));
    $resumed = $code->kanban(['finish']);

    expect($killed)->toBeTrue()
        ->and($state['phase'])->toBe('pushing')
        ->and($before)->toBe($origin)
        ->and($resumed->getExitCode())->toBe(0, $resumed->getOutput().$resumed->getErrorOutput())
        ->and($resumed->getOutput())->toContain("{$id}: its push did not land; merging again on the new main (round 2)")
        ->and($this->origin->log('main')[0])->toBe("{$id}: Add login page")
        ->and($code->sandbox->read($id)['stage'])->toBe('done')
        ->and($code->lease())->toBeNull();
});

it('installs every lockfile again in a merge clone made anew', function () {
    $code = $this->code;
    $log = $code->root().'/../installs.log';
    configureMerge($code, ['finish' => ['install' => ['composer.lock' => "echo \"install \$FAKE_DOCKER_EXEC\" >> {$log}"]]]);
    $first = $code->started('Bump deps');
    $code->commit($first, 'composer.lock', "{}\n");
    $code->approve($first);
    $code->ok(['finish', $first]);
    (new Process(['rm', '-rf', $code->mergeClone()]))->mustRun();
    $second = $code->started('Add login page');
    $code->commit($second, 'login.php', "<?php\n");
    $code->approve($second);

    $code->ok(['finish', $second]);

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['install 1', 'install ', 'install 1'])
        ->and($code->sandbox->read($second)['stage'])->toBe('done');
});

it('signals no process a stale run.json names when a stop meets a finish started by hand', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $runtime = $code->root().'/.git/laravel-house';
    // the pid a detached finish once had, now another process's group
    $stranger = new Process(['setsid', 'sleep', '300']);
    $stranger->start();
    file_put_contents($runtime.'/merge/run.json', json_encode(['pid' => $stranger->getPid(), 'card' => $id, 'started' => '2026-01-01T00:00:00.000+00:00']));
    $hand = new Process(['flock', $runtime.'/merge.run.lock', 'sleep', '300']);
    $hand->start();
    $held = function () use ($runtime): bool {
        $lock = fopen($runtime.'/merge.run.lock', 'c');
        $free = flock($lock, LOCK_SH | LOCK_NB);
        fclose($lock);

        return ! $free;
    };
    for ($until = microtime(true) + 10; microtime(true) < $until && ! $held();) {
        usleep(50_000);
    }

    try {
        $stop = $code->kanban(['stop', $id, '--to=backlog']);
        $alive = $stranger->isRunning();
    } finally {
        $stranger->stop(0);
        $hand->stop(0);
    }

    expect($stop->getExitCode())->toBe(11, $stop->getOutput().$stop->getErrorOutput())
        ->and($stop->getErrorOutput())->toContain('a merge runs here')
        ->and($alive)->toBeTrue();
});

it('counts a detached finish as running until its wrapper wrote its exit', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    $runtime = $code->root().'/.git/laravel-house';
    file_put_contents($runtime.'/merge.json', json_encode(['phase' => 'checks'] + $code->mergeState()));
    // the wrapper MergeRun started, its finish just ended and its exit not written yet
    $wrapper = new Process(['setsid', 'sh', '-c', 'sleep 300; echo $? > '.escapeshellarg($runtime.'/merge/exit')]);
    $wrapper->start();
    $run = ['pid' => $wrapper->getPid(), 'card' => $id, 'started' => '2026-01-01T00:00:00.000+00:00'];
    file_put_contents($runtime.'/merge/run.json', json_encode($run));
    for ($until = microtime(true) + 10; microtime(true) < $until && ! str_contains((string) @file_get_contents('/proc/'.$wrapper->getPid().'/cmdline'), 'merge/exit');) {
        usleep(50_000);
    }

    try {
        $code->kanban(['run', '--once'], ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => Sandbox::tmp()]);
        $after = json_decode((string) file_get_contents($runtime.'/merge/run.json'), true);
    } finally {
        $wrapper->stop(0);
        @unlink($runtime.'/merge/run.json');
        runSettled($code);
    }

    expect($after)->toBe($run);
});

it('ends only the merger of a merge it aborts: a worker of the card launched meanwhile goes on', function () {
    $code = $this->code;
    $id = $code->started('Breaks the suite');
    $code->commit($id, 'RED', "red\n");
    $code->approve($id);
    expect($code->kanban(['finish', $id])->getExitCode())->toBe(12);
    @mkdir($code->root().'/.git/laravel-house/runs', 0775, true);
    $runs = [];
    foreach (['kanban-merger' => 'a1b2c3d4-0000-4000-8000-000000000001', 'kanban-worker' => 'a1b2c3d4-0000-4000-8000-000000000002'] as $type => $session) {
        $runs[$type] = new Process(['setsid', 'sh', '-c', 'sleep 300; :', 'sh', $session]);
        $runs[$type]->start();
        file_put_contents($code->root()."/.git/laravel-house/runs/{$session}.pid", json_encode(['pid' => $runs[$type]->getPid(), 'card' => $id, 'type' => $type, 'stage' => 'review']));
    }

    try {
        $aborted = $code->kanban(['finish', $id, '--abort']);
        $alive = array_map(fn (Process $p) => $p->isRunning(), $runs);
    } finally {
        array_map(fn (Process $p) => $p->stop(0), $runs);
    }

    expect($aborted->getExitCode())->toBe(0, $aborted->getErrorOutput())
        ->and($aborted->getOutput())->toContain('stopped its merger a1b2c3d4')->not->toContain('stopped its worker')
        ->and($alive)->toBe(['kanban-merger' => false, 'kanban-worker' => true]);
});

it('waits, before the lease, while the main checkout holds a file git does not track where the card adds one, on a board without a remote', function () {
    $code = $this->code;
    $code->sandbox->git('remote', 'remove', 'origin');
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);
    file_put_contents($code->root().'/login.php', "<?php // left over\n");

    $waits = $code->kanban(['finish', $id]);
    unlink($code->root().'/login.php');
    $merged = $code->kanban(['finish', $id]);

    expect($waits->getExitCode())->toBe(11)
        ->and($waits->getErrorOutput())->toContain("main checkout not moved: the merge of {$id} adds login.php, which git does not track here: remove or commit it, then `kanban finish {$id}`")
        ->and($waits->getOutput())->not->toContain('gates and finish.check')
        ->and(array_values(array_filter($code->sandbox->boardLog(), fn (string $s) => str_contains($s, ' taken '))))->toBe(["merge lease {$id} taken [owner]"])
        ->and($merged->getExitCode())->toBe(0, $merged->getOutput().$merged->getErrorOutput())
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s', 'main')))->toBe("{$id}: Add login page");
});

it('counts a local fast-forward that a file in the main checkout keeps failing after the checks, and blocks the card at the third', function () {
    $code = $this->code;
    $code->sandbox->git('remote', 'remove', 'origin');
    $left = $code->root().'/login.php';
    // a file that turns up in the main checkout while the checks run
    configureMerge($code, ['gates' => ['report' => ["echo left > {$left}"]]]);
    $id = $code->started('Add login page');
    $code->commit($id, 'login.php', "<?php\n");
    $code->approve($id);

    $runs = [];
    foreach ([1, 2, 3] as $try) {
        $runs[] = $code->kanban(['finish', $id]);
        @unlink($left);
    }

    expect(array_map(fn ($r) => $r->getExitCode(), $runs))->toBe([11, 11, 11])
        ->and($runs[0]->getErrorOutput())->toContain('main checkout not moved: ')->toContain("; remove, commit or stash the files in the way, then `kanban finish {$id}`")
        ->and($runs[0]->getOutput())->toContain('gates and finish.check pass')
        ->and($code->lease())->toBeNull()
        ->and($code->sandbox->read($id)['blocked'])->toStartWith('merge failed 3×: main checkout not moved: ')
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s', 'main')))->not->toContain($id);
});
