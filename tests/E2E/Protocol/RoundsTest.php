<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
});

function stopAgent(ProtocolSandbox $p, string $wt, string $agent = 'a4d2c0ffee', string $type = 'kanban-worker'): array
{
    $process = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $wt, 'agent' => $agent, 'type' => $type]));

    return ['out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'json' => json_decode($process->getOutput(), true)];
}

/** The agent's heartbeat is half an hour old: it is gone without a stop. */
function gone(ProtocolSandbox $p, string $agent): void
{
    touch($p->runtime("agents/{$agent}.json"), time() - 30 * 60);
}

/** A merge of main the worker makes in its clone, as a card in review may hold from an earlier round. */
function mergeMain(ProtocolSandbox $p, string $wt): void
{
    $p->git($wt, 'pull', '-q', '--no-rebase', '--no-edit', $p->main, 'main');
}

function commitMain(ProtocolSandbox $p, string $file, string $content): void
{
    file_put_contents($p->main.'/'.$file, $content);
    $p->git($p->main, 'add', $file);
    $p->git($p->main, 'commit', '-q', '-m', "main: {$file}");
}

it('supersedes a verdict staged before a return to doing instead of applying it', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:"no test"'])->mustRun();
    gone($this->p, 'e1');
    $this->p->sandbox->ok(['move', $this->id, 'doing', '--reason=Handle the empty list']);

    $applied = $this->p->sandbox->ok(['apply', $this->id]);

    $card = $this->p->card($this->id);
    expect($applied)->toContain("{$this->id}: verdict superseded: a return to doing at ")
        ->and($card['stage'])->toBe('doing')
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, true])
        ->and(array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'verdict_superseded'))[0] ?? null)->toMatchArray(['decision' => 'reject'])
        ->and(glob($this->p->runtime('staged/*')))->toBe([]);
});

it('supersedes an approval of a head the branch has left when no evaluator is there to re-verify it', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    $this->p->commit($this->wt, 'more.php');
    gone($this->p, 'e1');

    expect($this->p->sandbox->ok(['apply', $this->id]))->toContain("{$this->id}: verdict superseded: it judged ")
        ->and($this->p->card($this->id)['work']['approved'])->toBeNull()
        ->and($this->p->sandbox->ok(['apply', $this->id]))->toContain('nothing staged');
});

it('discards a report staged before a conflicting refresh, and applies the one after it with its ticks', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n\nreturn 'branch';\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=First'])->mustRun();
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'app.php', "<?php\n\nreturn 'main';\n");

    $refresh = $this->p->sandbox->kanban(['refresh', $this->id]);
    expect($refresh->getExitCode())->toBe(5)
        ->and($refresh->getOutput())->toContain("discarded the report staged for {$this->id} before the merge")
        ->and($this->p->runtime("staged/{$this->id}.report.json"))->not->toBeFile();

    file_put_contents($this->wt.'/app.php', "<?php\n\nreturn 'branch and main';\n");
    $this->p->git($this->wt, 'add', 'app.php');
    $this->p->git($this->wt, 'commit', '-q', '--no-edit');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Merged'])->mustRun();

    expect(stopAgent($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['stage'])->toBe('review')
        ->and(array_column($this->p->card($this->id)['acceptance'], 'done'))->toBe([true, true]);
});

it('shows the evaluator every report of the attempt and the merges that resolved a conflict', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n\nreturn 'branch';\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=First pass', '--verified=pest --filter=Clauses → 4 passed'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->sandbox->ok(['move', $this->id, 'doing', '--reason=Handle the empty list']);
    commitMain($this->p, 'other.txt', "other\n");
    $this->p->sandbox->ok(['refresh', $this->id]);
    commitMain($this->p, 'app.php', "<?php\n\nreturn 'main';\n");
    $this->p->sandbox->kanban(['refresh', $this->id]);
    file_put_contents($this->wt.'/app.php', "<?php\n\nreturn 'branch and main';\n");
    $this->p->git($this->wt, 'add', 'app.php');
    $this->p->git($this->wt, 'commit', '-q', '--no-edit');
    $resolved = substr(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')), 0, 7);
    $clean = substr(trim($this->p->git($this->wt, 'rev-parse', 'HEAD^1')), 0, 7);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=2', '--summary=Second pass', '--verified=pest → 9 passed'])->mustRun();
    stopAgent($this->p, $this->wt);

    $context = $this->p->sandbox->ok(['context', $this->id, '--evaluate']);

    expect($context)->toMatch("/report 2\\/2 review .* ticks 2\n  Second pass\n  verified: pest → 9 passed\nreport 1\\/2 review .* ticks 1\n  First pass\n  verified: pest --filter=Clauses → 4 passed\n/")
        ->and($context)->toContain("merge resolutions (lines neither parent had; read each with `git show <sha>`):\n  {$resolved} ")
        ->and($context)->not->toContain("  {$clean} ");
});

it('keeps at most five reports in the evaluator context and points to the rest', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    foreach (range(1, 7) as $n) {
        $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
        $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Question '.$n, '--summary='.str_repeat("Pass {$n} ", 200)])->mustRun();
        stopAgent($this->p, $this->wt);
    }

    $context = $this->p->sandbox->ok(['context', $this->id, '--evaluate']);

    expect($context)->toContain('report 7/7 blocked')->not->toContain('report 2/7')
        ->and($context)->toMatch('/… [2-6] earlier: `vendor\/bin\/kanban show '.$this->id.' --log=50`/');
});

it('names the gate that refused a new report beside the staged one in status, context and apply', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $this->p->config(['gates' => ['report' => ['php -r \'for ($i = 1; $i <= 60; $i++) { echo "test $i of the suite failed with a long message\n"; } echo "3 tests failed\n"; exit(2);\'']]]);
    expect($this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done again'])->getExitCode())->not->toBe(0);
    $gate = 'report staged, not applied: Gate failed: `php -r \'for ($i = 1; $i <= 60; $i++) {';

    expect($this->p->sandbox->ok(['status']))->toMatch('/^doing  '.$this->id.' .*'.preg_quote($gate, '/').'.*\(exit 2\)$/m')
        ->and($this->p->in($this->wt, ['context'])->getOutput())->toMatch("/your staged report was not applied \\(\\S+\\):\n  Gate failed: `php -r .*\\(exit 2\\):\n  ….*\n(  test \\d+ of the suite failed with a long message\n)+  3 tests failed\n/")
        ->and($this->p->sandbox->ok(['apply']))->toContain("{$this->id}: report waits for live agent a4d2c0ffee (applied when it stops); last refused: Gate failed: ");
});

it('keeps why the stop hook failed beside the staged report', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $file = glob($this->p->main.'/docs/kanban/*/'.$this->id.'.json')[0];
    $card = file_get_contents($file);
    file_put_contents($file, '{');

    $stop = $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    file_put_contents($file, $card);

    expect($stop->getExitCode())->toBe(1)
        ->and($this->p->sandbox->ok(['status']))->toMatch('/^doing  '.$this->id.' .*, report staged, not applied: hook failed: /m')
        ->and($this->p->sandbox->kanban(['refresh', $this->id])->getErrorOutput())->toContain('its stop hook failed, so `vendor/bin/kanban apply` settles it');
});

it('never merges main into uncommitted changes', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    file_put_contents($this->wt.'/app.php', "<?php\n\nreturn 'wip';\n");
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'other.txt', "other\n");
    $head = trim($this->p->git($this->wt, 'rev-parse', 'HEAD'));

    $one = $this->p->sandbox->kanban(['refresh', $this->id]);
    $all = $this->p->sandbox->kanban(['refresh', '--all']);

    expect($one->getExitCode())->toBe(3)
        ->and($one->getErrorOutput())->toContain("{$this->id}: uncommitted changes in its clone; its worker commits them before main is merged in")
        ->and($one->getErrorOutput())->toContain('app.php')
        ->and($all->getExitCode())->toBe(3)
        ->and($all->getOutput())->toContain("skipped {$this->id}: {$this->id}: uncommitted changes in its clone")
        ->and(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')))->toBe($head);
});

it('refuses a stop whose merge of main carries changes neither side had, and rebuild-branch makes it one commit with the same files', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n\nreturn 'branch';\n", "{$this->id}: clauses");
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'app.php', "<?php\n\nreturn 'main';\n");
    expect($this->p->sandbox->kanban(['refresh', $this->id])->getExitCode())->toBe(5);
    file_put_contents($this->wt.'/app.php', "<?php\n\nreturn 'branch and main';\n");
    file_put_contents($this->wt.'/stray.php', "<?php\n");
    $this->p->git($this->wt, 'add', '-A');
    $this->p->git($this->wt, 'commit', '-q', '--no-edit');
    $merge = substr(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')), 0, 7);
    commitMain($this->p, 'later.txt', "later\n");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Merged'])->mustRun();

    $refused = stopAgent($this->p, $this->wt);
    expect($refused['json']['decision'] ?? null)->toBe('block')
        ->and($refused['json']['reason'])->toContain('A merge of main carries changes neither side had')
        ->toContain("{$merge}: stray.php")->toContain("vendor/bin/kanban rebuild-branch {$this->id}")
        ->and($this->p->card($this->id)['stage'])->toBe('doing');

    $tree = trim($this->p->git($this->wt, 'rev-parse', 'HEAD^{tree}'));
    $rebuilt = $this->p->in($this->wt, ['--in='.$this->wt, 'rebuild-branch', $this->id], ['KANBAN_SESSION' => 's1']);
    $onto = trim($this->p->git($this->wt, 'rev-parse', 'HEAD^'));

    expect($rebuilt->getExitCode())->toBe(0)
        ->and($rebuilt->getOutput())->toContain("discarded the report staged for {$this->id} before the rebuild")->toContain('with the same files')
        ->and(trim($this->p->git($this->wt, 'rev-parse', 'HEAD^{tree}')))->toBe($tree)
        ->and(trim($this->p->git($this->wt, 'rev-list', '--count', '--merges', 'HEAD')))->toBe('0')
        ->and(trim($this->p->git($this->wt, 'diff', '--name-only', $onto, 'HEAD')))->toBe("app.php\nstray.php")
        ->and(end($this->p->card($this->id)['log']))->toMatchArray(['event' => 'rebuilt', 'by' => 'worker', 'onto' => $onto]);

    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Rebuilt'])->mustRun();
    expect(stopAgent($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['stage'])->toBe('review');
});

it('lets a merge that resolves a modify/delete conflict through the stop gate', function () {
    commitMain($this->p, 'shared.txt', "one\n");
    gone($this->p, 'a4d2c0ffee');
    $this->p->sandbox->ok(['refresh', $this->id]);
    $this->p->commit($this->wt, 'shared.txt', "one\ntwo\n", "{$this->id}: shared");
    $this->p->git($this->p->main, 'rm', '-q', 'shared.txt');
    $this->p->git($this->p->main, 'commit', '-q', '-m', 'main: drop shared');
    expect($this->p->sandbox->kanban(['refresh', $this->id])->getExitCode())->toBe(5);
    $this->p->git($this->wt, 'add', 'shared.txt');
    $this->p->git($this->wt, 'commit', '-q', '--no-edit');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Kept it'])->mustRun();

    expect(stopAgent($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['stage'])->toBe('review');
});

it('judges only the card\'s own merges, not the merges main brought along', function () {
    $this->p->git($this->p->main, 'checkout', '-q', '-b', 'side');
    file_put_contents($this->p->main.'/side.txt', "side\n");
    $this->p->git($this->p->main, 'add', 'side.txt');
    $this->p->git($this->p->main, 'commit', '-q', '-m', 'side');
    $this->p->git($this->p->main, 'checkout', '-q', 'main');
    $this->p->git($this->p->main, 'merge', '-q', '--no-ff', '--no-commit', 'side');
    file_put_contents($this->p->main.'/extra.txt', "made in the merge\n");
    $this->p->git($this->p->main, 'add', 'extra.txt');
    $this->p->git($this->p->main, 'commit', '-q', '--no-edit');
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    gone($this->p, 'a4d2c0ffee');
    $this->p->sandbox->ok(['refresh', $this->id]);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();

    expect(stopAgent($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['stage'])->toBe('review');
});

it('rebuilds a card in review whose follow-up report the stop gate refused for a merge', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    commitMain($this->p, 'other.txt', "other\n");
    mergeMain($this->p, $this->wt);
    file_put_contents($this->wt.'/stray.php', "<?php\n");
    $this->p->git($this->wt, 'add', 'stray.php');
    $this->p->git($this->wt, 'commit', '-q', '--amend', '--no-edit');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Follow-up'])->mustRun();
    expect(stopAgent($this->p, $this->wt)['json']['reason'] ?? '')->toContain('rebuild-branch');
    file_put_contents($this->wt.'/screenshot.png', "png\n");
    $this->p->sandbox->ok(['set', $this->id, 'note=checked']);

    $rebuilt = $this->p->in($this->wt, ['--in='.$this->wt, 'rebuild-branch', $this->id]);

    expect($rebuilt->getExitCode())->toBe(0)
        ->and($this->p->card($this->id))->toMatchArray(['stage' => 'review'])
        ->and($this->p->card($this->id)['work']['approved'])->toBeNull()
        ->and(is_file($this->wt.'/screenshot.png'))->toBeTrue();
    unlink($this->wt.'/screenshot.png');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Rebuilt'])->mustRun();
    expect(stopAgent($this->p, $this->wt)['out'])->toBe('');
});

it('runs none of the clone\'s signing config on this machine', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $marker = $this->p->main.'/.git/signed-on-host';
    file_put_contents($this->p->main.'/.git/sign.sh', "#!/bin/sh\ntouch {$marker}\nexit 1\n");
    chmod($this->p->main.'/.git/sign.sh', 0755);
    $this->p->git($this->wt, 'config', 'commit.gpgSign', 'true');
    $this->p->git($this->wt, 'config', 'gpg.program', $this->p->main.'/.git/sign.sh');
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'other.txt', "other\n");

    $this->p->sandbox->ok(['refresh', $this->id]);

    expect($marker)->not->toBeFile();
});

it('refuses to rebuild a clone that is not on the card\'s branch, or whose evaluator runs', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    commitMain($this->p, 'other.txt', "other\n");
    mergeMain($this->p, $this->wt);
    file_put_contents($this->wt.'/stray.php', "<?php\n");
    $this->p->git($this->wt, 'add', 'stray.php');
    $this->p->git($this->wt, 'commit', '-q', '--amend', '--no-edit');
    $this->p->git($this->wt, 'checkout', '-q', '--detach');

    $detached = $this->p->in($this->wt, ['--in='.$this->wt, 'rebuild-branch', $this->id]);
    expect($detached->getExitCode())->toBe(3)
        ->and($detached->getErrorOutput())->toContain('not on its branch');

    $this->p->git($this->wt, 'checkout', '-q', '-');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $live = $this->p->sandbox->kanban(['rebuild-branch', $this->id], ['KANBAN_SESSION' => 's1']);
    expect($live->getExitCode())->toBe(3)
        ->and($live->getErrorOutput())->toContain('evaluator is still running');
});

it('never merges main into a clone that is not on the card\'s branch', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->git($this->wt, 'checkout', '-q', '-b', 'elsewhere');
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'other.txt', "other\n");

    $refresh = $this->p->sandbox->kanban(['refresh', $this->id]);

    expect($refresh->getExitCode())->toBe(3)
        ->and($refresh->getErrorOutput())->toContain('not on its branch')
        ->and(trim($this->p->git($this->wt, 'rev-list', '--count', '--merges', 'HEAD')))->toBe('0');
});

it('takes no one in the clone but its worker for the worker', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    commitMain($this->p, 'other.txt', "other\n");
    mergeMain($this->p, $this->wt);
    file_put_contents($this->wt.'/stray.php', "<?php\n");
    $this->p->git($this->wt, 'add', 'stray.php');
    $this->p->git($this->wt, 'commit', '-q', '--amend', '--no-edit');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');

    $evaluator = $this->p->in($this->wt, ['--in='.$this->wt, 'rebuild-branch', $this->id]);
    $main = $this->p->in($this->wt, ['rebuild-branch', $this->id], ['KANBAN_SESSION' => 's1']);
    $owner = $this->p->in($this->wt, ['rebuild-branch', $this->id]);

    expect($evaluator->getExitCode())->toBe(3)
        ->and($evaluator->getErrorOutput())->toContain('evaluator is still running')
        ->and($main->getExitCode())->toBe(3)
        ->and($main->getErrorOutput())->toContain('rebuild-branch runs from the main checkout')
        ->and($owner->getExitCode())->toBe(3)
        ->and($owner->getErrorOutput())->toContain('rebuild-branch runs from the main checkout');
});

it('refuses a blocked report over uncommitted work', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    file_put_contents($this->wt.'/wip.php', "<?php\n");
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=needs the mailer on main'])->mustRun();

    $stop = stopAgent($this->p, $this->wt);

    expect($stop['json']['decision'] ?? null)->toBe('block')
        ->and($stop['json']['reason'])->toContain('uncommitted changes')
        ->and($this->p->card($this->id)['blocked'])->toBeNull();
});

it('lets a worker report blocked on a merge of main it cannot resolve, the merge kept in progress', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n\nreturn 'branch';\n", "{$this->id}: clauses");
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'app.php', "<?php\n\nreturn 'main';\n");
    expect($this->p->sandbox->kanban(['refresh', $this->id])->getExitCode())->toBe(5);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=which return value wins is the owner\'s call'])->mustRun();

    expect(stopAgent($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['blocked'])->toBe('which return value wins is the owner\'s call')
        ->and(trim($this->p->git($this->wt, 'rev-parse', '-q', '--verify', 'MERGE_HEAD')))->not->toBe('');
});

it('refuses a refresh and a review report while a merge is in progress, all of it staged', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n\nreturn 'branch';\n", "{$this->id}: clauses");
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'app.php', "<?php\n\nreturn 'main';\n");
    $this->p->sandbox->kanban(['refresh', $this->id]);
    $this->p->git($this->wt, 'checkout', '--ours', 'app.php');
    $this->p->git($this->wt, 'add', 'app.php');
    commitMain($this->p, 'other.txt', "other\n");

    $refresh = $this->p->sandbox->kanban(['refresh', $this->id]);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done']);

    expect($refresh->getExitCode())->toBe(3)
        ->and($refresh->getErrorOutput())->toContain('a merge of main is in progress')
        ->and(stopAgent($this->p, $this->wt)['json']['reason'] ?? '')->toContain('A merge of main is in progress');
});

it('applies a reject and its return to doing in one write', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:"no test"'])->mustRun();
    $commits = count($this->p->sandbox->boardLog());

    $stop = stopAgent($this->p, $this->wt, 'e1', 'kanban-evaluator');

    $card = $this->p->card($this->id);
    // one write's entries share their time, and the log orders them by id
    $last = array_values(array_filter($card['log'], fn ($e) => $e['at'] === end($card['log'])['at']));
    $of = fn (string $event) => array_values(array_filter($last, fn ($e) => $e['event'] === $event));
    expect($stop['err'])->toContain("{$this->id}: rejected, stage doing")
        ->and($card['stage'])->toBe('doing')
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, false])
        ->and(count($this->p->sandbox->boardLog()))->toBe($commits + 1)
        // the history a separate write of each would have left: the UI names a change to the criteria being edited
        ->and($last)->toHaveCount(3)
        ->and($of('verdict'))->toHaveCount(1)
        ->and($of('set')[0] ?? null)->toMatchArray(['fields' => ['acceptance']])
        ->and($of('stage')[0] ?? null)->toMatchArray(['from' => 'review', 'to' => 'doing', 'via' => 'reject']);
});

it('applies a staged item whose write landed but was not marked applied only once', function (string $kind) {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    if ($kind === 'verdict') {
        stopAgent($this->p, $this->wt);
        $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
        $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
        $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    }
    $staged = $this->p->runtime("staged/{$this->id}.{$kind}.json");
    $bytes = file_get_contents($staged);
    $kind === 'verdict' ? stopAgent($this->p, $this->wt, 'e1', 'kanban-evaluator') : stopAgent($this->p, $this->wt);
    // the write landed; marking it applied did not
    file_put_contents($staged, $bytes);
    array_map('unlink', glob($this->p->runtime("applied/{$this->id}.*.{$kind}.json")));

    $again = $this->p->sandbox->ok(['apply', $this->id]);

    expect($again)->toContain("{$this->id}: {$kind} ")->toContain(' already applied')
        ->and(count(array_filter($this->p->card($this->id)['log'], fn ($e) => $e['event'] === $kind)))->toBe(1)
        ->and(is_file($staged))->toBeFalse();
})->with(['report', 'verdict']);

it('applies the same report again once the card moved on from the first', function () {
    $report = ['report', $this->id, '--status=blocked', '--reason=Which currency does the total use?'];
    $this->p->in($this->wt, $report)->mustRun();
    stopAgent($this->p, $this->wt);
    expect($this->p->card($this->id)['blocked'])->toBe('Which currency does the total use?');
    $this->p->sandbox->ok(['set', $this->id, 'blocked=']);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);

    $this->p->in($this->wt, $report)->mustRun();
    $stop = stopAgent($this->p, $this->wt);

    $card = $this->p->card($this->id);
    expect($stop['err'])->toContain("{$this->id}: report applied, stage doing, blocked")
        ->and($card['blocked'])->toBe('Which currency does the total use?')
        ->and(count(array_filter($card['log'], fn ($e) => $e['event'] === 'report')))->toBe(2);
});

it('applies the same verdict again in a later round, staged with no evaluator bound', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $verdict = ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:"no test"'];
    foreach ([1, 2] as $round) {
        $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
        $this->p->enter($this->wt);
        $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
        stopAgent($this->p, $this->wt);
        $this->p->in($this->wt, $verdict)->mustRun();
        $applied = $this->p->sandbox->ok(['apply', $this->id]);
    }

    $card = $this->p->card($this->id);
    expect($applied)->toContain("{$this->id}: rejected, stage doing")
        ->and($card['stage'])->toBe('doing')
        ->and(count(array_filter($card['log'], fn ($e) => $e['event'] === 'verdict')))->toBe(2);
});
