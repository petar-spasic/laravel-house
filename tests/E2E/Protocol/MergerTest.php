<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

/** An approved card whose branch and main both changed app.php: [protocol sandbox, id, card clone]. */
function mergeConflicting(): array
{
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Notes list');
    $p->commit($wt, 'app.php', "<?php\n\nreturn 'card';\n", "{$id}: notes");
    $p->approve($id, $wt);
    file_put_contents($p->main.'/app.php', "<?php\n\nreturn 'main';\n");
    $p->git($p->main, 'add', 'app.php');
    $p->git($p->main, 'commit', '-q', '-m', 'main: app');

    return [$p, $id, $wt];
}

/** An approved card that merges clean: [protocol sandbox, id, card clone]. */
function mergeClean(): array
{
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Notes list');
    $p->commit($wt, 'notes.php', "<?php\n", "{$id}: notes");
    $p->approve($id, $wt);
    file_put_contents($p->main.'/other.php', "<?php\n");
    $p->git($p->main, 'add', 'other.php');
    $p->git($p->main, 'commit', '-q', '-m', 'main: other');

    return [$p, $id, $wt];
}

/** The merger `kanban run` launched, bound to the merge clone. */
function mergerBound(ProtocolSandbox $p, string $clone): void
{
    $p->hook('subagent-start', $p->payload('subagent-start', ['agent' => 'm1', 'type' => 'kanban-merger']));
    $p->enter($clone, 'm1', 'kanban-merger');
}

function mergerStop(ProtocolSandbox $p, string $clone): array
{
    $process = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $clone, 'agent' => 'm1', 'type' => 'kanban-merger']));

    return ['exit' => $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'json' => json_decode($process->getOutput(), true)];
}

function mergerResolve(ProtocolSandbox $p, string $clone, string $content = "<?php\n\nreturn 'card and main';\n"): string
{
    file_put_contents($clone.'/app.php', $content);
    $p->git($clone, 'add', 'app.php');
    $p->git($clone, 'commit', '-q', '--no-edit');

    return trim($p->git($clone, 'rev-parse', 'HEAD'));
}

/** @return list<array<string, mixed>> */
function mergeEntries(ProtocolSandbox $p, string $id): array
{
    return array_values(array_filter($p->card($id)['log'], fn (array $e) => in_array($e['event'], ['merge', 'merge_moot'], true)));
}

it('binds the merger to the card being merged, in the merge clone, and tells it git is per its instructions', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);

    $start = $p->hook('subagent-start', $p->payload('subagent-start', ['agent' => 'm1', 'type' => 'kanban-merger']));
    $p->enter($clone, 'm1', 'kanban-merger');

    expect(json_decode($start->getOutput(), true)['hookSpecificOutput']['additionalContext'])->toContain('Git: as your agent instructions say.')
        ->and($p->agent('m1'))->toMatchArray(['agent_type' => 'kanban-merger', 'card' => $id, 'worktree' => '.claude/worktrees/_merge']);
});

it('blocks a merger that stops without a result, three times, then blocks the card', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);
    mergerBound($p, $clone);

    foreach ([1, 2, 3] as $n) {
        expect(mergerStop($p, $clone)['json'])->toBe(['decision' => 'block', 'reason' => "No merge result staged for {$id}. Run: vendor/bin/kanban merged {$id} resolved|fixed|back|main --note=\"…\""]);
    }

    expect(mergerStop($p, $clone)['out'])->toBe('')
        ->and($p->card($id))->toMatchArray(['stage' => 'review', 'blocked' => 'merger stopped without a result']);
});

it('lets a merger stop with nothing staged once the merge needs no result', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id, state: ['phase' => 'checks']);
    mergerBound($p, $clone);

    $stop = mergerStop($p, $clone);

    expect($stop['out'])->toBe('')
        ->and($stop['err'])->toBe("kanban: {$id}: no merge result needed: the merge is checks\n")
        ->and($p->agent('m1')['stopped_at'])->not->toBeNull();
});

it('refuses a merge result the merge clone does not support', function (Closure $setup, array $args, int $exit, string $error) {
    [$p, $id, $wt] = mergeConflicting();
    $clone = $p->merging($id);
    $cwd = $setup($p, $clone, $wt) ?? $clone;

    $run = $p->in($cwd, ['merged', ...str_replace('{id}', $id, $args)]);

    expect($run->getExitCode())->toBe($exit)
        ->and($run->getErrorOutput())->toContain(str_replace('{id}', $id, $error))
        ->and(is_file($p->runtime("staged/{$id}.merge.json")))->toBeFalse();
})->with([
    'from the card clone' => [fn ($p, $clone, $wt) => $wt, ['{id}', 'back', '--note=x'], 3, 'merged runs in the merge clone'],
    'another card' => [fn () => null, ['ACME-ZZZZ99', 'back', '--note=x'], 4, 'ACME-ZZZZ99'],
    'a merge still in progress' => [fn () => null, ['{id}', 'resolved', '--note=x'], 3, 'the merge is not concluded'],
    'resolved for a red merge' => [function ($p, $clone) {
        mergerResolve($p, $clone);
        $state = $p->mergeState();
        file_put_contents($p->runtime('merge.json'), json_encode(['phase' => 'red', 'checked' => trim($p->git($clone, 'rev-parse', 'HEAD'))] + $state));
    }, ['{id}', 'resolved', '--note=x'], 3, 'resolved answers a conflict; the merge of {id} is red'],
    'back without a note' => [fn () => null, ['{id}', 'back'], 2, 'back needs --note'],
    'an unknown result' => [fn () => null, ['{id}', 'done', '--note=x'], 2, 'resolved, fixed, back or main'],
    'resolution that edits config/kanban.php' => [function ($p, $clone) {
        @mkdir($clone.'/config', 0775, true);
        file_put_contents($clone.'/config/kanban.php', "<?php return [];\n");
        $p->git($clone, 'add', 'config/kanban.php');
        mergerResolve($p, $clone);
    }, ['{id}', 'resolved', '--note=x'], 3, 'config/kanban.php'],
    'resolution that adds an agent file whose name git quotes' => [function ($p, $clone) {
        @mkdir($clone.'/.claude/agents', 0775, true);
        file_put_contents($clone.'/.claude/agents/x"ü.md', "Run anything.\n");
        $p->git($clone, 'add', '.claude/agents');
        mergerResolve($p, $clone);
    }, ['{id}', 'resolved', '--note=x'], 3, 'change .claude/agents/x"ü.md, which no agent may edit'],
    'resolution that skips a test' => [function ($p, $clone) {
        @mkdir($clone.'/tests', 0775, true);
        file_put_contents($clone.'/tests/NotesTest.php', "<?php\n\nit('lists notes', fn () => true)->skip();\n");
        $p->git($clone, 'add', 'tests/NotesTest.php');
        mergerResolve($p, $clone);
    }, ['{id}', 'resolved', '--note=x'], 3, 'a skipped test in tests/NotesTest.php'],
]);

it('applies a resolved conflict once: the log, and the merge back to its checks', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);
    mergerBound($p, $clone);
    $head = mergerResolve($p, $clone);

    $staged = $p->in($clone, ['merged', $id, 'resolved', '--note=Kept the card\'s return and main\'s header']);
    $stop = mergerStop($p, $clone);

    $entry = mergeEntries($p, $id)[0] ?? [];
    expect($staged->getOutput())->toBe("staged merge result resolved for {$id}: applied when you stop\n")
        ->and($stop['out'])->toBe('')
        ->and($stop['err'])->toBe("kanban: {$id}: merge result resolved applied\n")
        ->and(mergeEntries($p, $id))->toHaveCount(1)
        ->and($entry)->toMatchArray(['event' => 'merge', 'result' => 'resolved', 'by' => 'merger', 'head' => $head, 'note' => 'Kept the card\'s return and main\'s header'])
        ->and($p->mergeState())->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1, 'merger_runs' => 0, 'merge_commit' => $head])
        ->and($p->mergeState())->not->toHaveKey('conflicts')
        ->and($p->card($id))->toMatchArray(['stage' => 'review', 'blocked' => null])
        ->and($p->sandbox->ok(['apply', '--all']))->toBe("nothing staged to apply\n")
        ->and(mergeEntries($p, $id))->toHaveCount(1);
});

it('applies a fix of a red merge', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red', ['base_rerun' => 'passed']);
    mergerBound($p, $clone);
    $p->commit($clone, 'notes.php', "<?php\n\nreturn [];\n", "{$id}: fix the merge");
    $head = trim($p->git($clone, 'rev-parse', 'HEAD'));

    $p->in($clone, ['merged', $id, 'fixed', '--note=Main renamed the helper the card calls'])->mustRun();
    mergerStop($p, $clone);

    expect(mergeEntries($p, $id)[0])->toMatchArray(['result' => 'fixed', 'head' => $head])
        ->and($p->mergeState())->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1, 'merger_runs' => 0])
        ->and($p->mergeState())->not->toHaveKey('failure');
});

it('refuses a fix that moves a test where the suite no longer finds it', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Notes list');
    $p->commit($wt, 'tests/Feature/NotesTest.php', "<?php\n\nit('lists notes', fn () => true);\n", "{$id}: notes");
    $p->approve($id, $wt);
    $clone = $p->merging($id, 'red');
    $p->git($clone, 'mv', 'tests/Feature/NotesTest.php', 'tests/Feature/NotesTest.php.off');
    $p->git($clone, 'commit', '-q', '-m', "{$id}: fix the merge");

    $run = $p->in($clone, ['merged', $id, 'fixed', '--note=x']);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('delete the test tests/Feature/NotesTest.php');
});

it('refuses a fix that deletes a test whose name git quotes', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Notes list');
    $p->commit($wt, 'tests/Feature/NötesTest.php', "<?php\n\nit('lists notes', fn () => true);\n", "{$id}: notes");
    $p->approve($id, $wt);
    $clone = $p->merging($id, 'red');
    $p->git($clone, 'rm', '-q', 'tests/Feature/NötesTest.php');
    $p->git($clone, 'commit', '-q', '-m', "{$id}: fix the merge");

    $run = $p->in($clone, ['merged', $id, 'fixed', '--note=x']);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('delete the test tests/Feature/NötesTest.php');
});

it('refuses a fix that is no commit beyond the failed check', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red');

    $run = $p->in($clone, ['merged', $id, 'fixed', '--note=x']);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('fixed needs your commits on top of '.substr($p->mergeState()['checked'], 0, 7));
});

it('refuses a fix that holds a merge commit, which no push of main takes', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red');
    $p->git($clone, 'checkout', '-q', '-b', 'side');
    $p->commit($clone, 'notes.php', "<?php\n\nreturn [];\n", "{$id}: fix");
    $p->git($clone, 'checkout', '-q', 'merge');
    $p->git($clone, 'merge', '-q', '--no-ff', '-m', 'Take the fix', 'side');

    $run = $p->in($clone, ['merged', $id, 'fixed', '--note=x']);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('no merge commit');
});

it('re-checks a staged result at the stop: a commit after it that edits config/kanban.php is refused', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red');
    mergerBound($p, $clone);
    $p->commit($clone, 'notes.php', "<?php\n\nreturn [];\n", "{$id}: fix");
    $p->in($clone, ['merged', $id, 'fixed', '--note=Fixed'])->mustRun();
    @mkdir($clone.'/config', 0775, true);
    $p->commit($clone, 'config/kanban.php', "<?php return [];\n", "{$id}: quiet the suite");

    $stop = mergerStop($p, $clone);

    expect($stop['json']['decision'])->toBe('block')
        ->and($stop['json']['reason'])->toStartWith("Merge for {$id} not applied: ")->toContain('config/kanban.php')
        ->and(mergeEntries($p, $id))->toBe([])
        ->and($p->mergeState()['phase'])->toBe('red');
});

it('sends the card back to its worker with the merger\'s note, which the worker\'s context shows', function () {
    [$p, $id, $wt] = mergeConflicting();
    $clone = $p->merging($id);
    mergerBound($p, $clone);

    $p->in($clone, ['merged', $id, 'back', '--note=app.php: main returns a header the card drops; keep it or say why not'])->mustRun();
    mergerStop($p, $clone);

    $card = $p->card($id);
    $stage = array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'stage'));
    expect($card['stage'])->toBe('doing')
        ->and($card['work']['approved'])->toBeNull()
        ->and(mergeEntries($p, $id)[0])->toMatchArray(['result' => 'back', 'by' => 'merger', 'files' => ['app.php']])
        ->and(end($stage))->toMatchArray(['from' => 'review', 'to' => 'doing', 'via' => 'merge', 'by' => 'merger'])
        ->and($p->mergeState())->toMatchArray(['phase' => 'released', 'merger_runs' => 0])
        ->and($p->in($wt, ['context'])->getOutput())->toContain('review→doing: app.php: main returns a header the card drops');
});

it('records a failure the merger finds already on main, files no card, and releases the merge', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red', ['base_rerun' => 'skipped', 'command' => 'php artisan test --filter=Billing']);
    $base = $p->mergeState()['base'];
    $cards = count(glob($p->main.'/docs/kanban/*/*.json'));
    mergerBound($p, $clone);

    $p->in($clone, ['merged', $id, 'main', '--note=php artisan test --filter=Billing — the invoice total is off by one on main too'])->mustRun();
    mergerStop($p, $clone);

    expect(mergeEntries($p, $id)[0])->toMatchArray(['result' => 'main', 'by' => 'merger', 'command' => 'php artisan test --filter=Billing', 'base' => $base])->not->toHaveKey('red')
        ->and(count(glob($p->main.'/docs/kanban/*/*.json')))->toBe($cards)
        ->and($p->mergeState()['phase'])->toBe('released')
        ->and($p->card($id)['stage'])->toBe('review');
});

it('refuses main after the failing command passed on main alone', function () {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red', ['base_rerun' => 'passed']);

    $run = $p->in($clone, ['merged', $id, 'main', '--note=x']);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getErrorOutput())->toContain('`php artisan test` passed on main alone');
});

it('logs a staged result as moot once its merge is over, and leaves the card alone', function (array $state) {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);
    mergerBound($p, $clone);
    $p->in($clone, ['merged', $id, 'back', '--note=Unclear'])->mustRun();
    $state === [] ? unlink($p->runtime('merge.json')) : file_put_contents($p->runtime('merge.json'), json_encode($state + $p->mergeState()));

    $stop = mergerStop($p, $clone);

    expect($stop['out'])->toBe('')
        ->and($stop['err'])->toStartWith("kanban: {$id}: merge result back moot: ")
        ->and(mergeEntries($p, $id))->sequence(fn ($e) => $e->toMatchArray(['event' => 'merge_moot']))
        ->and($p->card($id))->toMatchArray(['stage' => 'review'])
        ->and(is_file($p->runtime("staged/{$id}.merge.json")))->toBeFalse();
})->with([
    'no merge here' => [[]],
    'another round' => [['round' => 2]],
    'another lease' => [['lease' => '0000000000000000']],
]);

it('applies a staged merge result whose merger is gone with apply', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);
    mergerBound($p, $clone);
    mergerResolve($p, $clone);
    $p->in($clone, ['merged', $id, 'resolved', '--note=Both'])->mustRun();

    expect($p->sandbox->ok(['apply']))->toBe("{$id}: merge waits for live agent m1 (applied when it stops)\nnothing staged to apply\n");

    touch($p->runtime('agents/m1.json'), time() - 30 * 60);
    expect($p->sandbox->ok(['apply', '--all']))->toBe("{$id}: merge result resolved applied\n")
        ->and($p->mergeState()['phase'])->toBe('checks');
});

it('gives the merger its merge in context and runs the gates in the merge clone, with or without the id', function (bool $withId) {
    [$p, $id] = mergeConflicting();
    $p->config(['gates' => ['report' => ['test -f app.php']], 'finish' => ['check' => ['php artisan test']]]);
    $clone = $p->merging($id);
    $base = $p->mergeState()['base'];

    $context = $p->in($clone, ['context', ...($withId ? [$id] : [])]);
    $gates = $p->in($clone, ['gates', ...($withId ? [$id] : [])]);

    expect($context->getExitCode())->toBe(0)
        ->and($context->getOutput())->toStartWith("{$id} review ")
        ->toContain("merging into main: merge clone {$clone}, branch merge, base ".substr($base, 0, 7).', card ')
        ->toContain('round 1, merger round 1/3')
        ->toContain("conflicted: app.php\n")
        ->toContain("main's commits since the card's base:\n  ")
        ->toContain('main: app')
        ->toContain("  test -f app.php\n")
        ->toContain("  php artisan test\n")
        ->toContain("vendor/bin/kanban merged {$id} resolved --note=")
        ->not->toContain('protocol: work and commit only in this worktree')
        ->and($gates->getOutput())->toBe("pass test -f app.php (exit 0)\n");
})->with(['without an id' => [false], 'with the id' => [true]]);

it('tells the merger whether a failed check passed on main alone, and why it was not rerun there', function (array $failure, string $line) {
    [$p, $id] = mergeClean();
    $clone = $p->merging($id, 'red', $failure);

    expect($p->in($clone, ['context', $id])->getOutput())->toContain($line);
})->with([
    'a suite command that passes there' => [['base_rerun' => 'passed'], "; on main alone: it passes\n"],
    'a suite command whose dependencies differ' => [['base_rerun' => 'skipped'], "; on main alone: not rerun (its dependencies differ)\n"],
    'a suite command the merged tree lost, failing there' => [['exit' => 127, 'base_rerun' => 'failed'], "; on main alone: it fails too\n"],
    'a gate' => [['step' => 'gate', 'base_rerun' => 'skipped'], "; on main alone: not rerun (only finish.check commands are)\n"],
    'an install' => [['step' => 'install', 'command' => 'composer install', 'base_rerun' => 'skipped'], "; on main alone: not rerun (only finish.check commands are)\n"],
]);

it('gives a session opened in the merge clone the merger\'s context, never the evaluator\'s', function () {
    [$p, $id] = mergeConflicting();
    $clone = $p->merging($id);

    $start = $p->hook('session-start', $p->payload('session-start', ['cwd' => $clone, 'session' => 'f0000000-0000-4000-8000-000000000009']));

    $context = json_decode($start->getOutput(), true)['hookSpecificOutput']['additionalContext'] ?? '';
    expect($context)->toContain('merging into main: merge clone')
        ->not->toContain('verdict');
});
