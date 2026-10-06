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

function commitMain(ProtocolSandbox $p, string $file, string $content): void
{
    file_put_contents($p->main.'/'.$file, $content);
    $p->git($p->main, 'add', $file);
    $p->git($p->main, 'commit', '-q', '-m', "main: {$file}");
}

it('supersedes a verdict staged before a refresh instead of applying it', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:"no test"'])->mustRun();
    gone($this->p, 'e1');
    commitMain($this->p, 'other.txt', "other\n");
    $this->p->sandbox->ok(['refresh', $this->id]);

    $applied = $this->p->sandbox->ok(['apply', $this->id]);

    $card = $this->p->card($this->id);
    expect($applied)->toContain("{$this->id}: verdict superseded: a refresh at ")
        ->and($card['stage'])->toBe('review')
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

it('asks only for a re-verify when nothing but a clean merge of main followed the approval', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopAgent($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    stopAgent($this->p, $this->wt, 'e1', 'kanban-evaluator');
    $approved = $this->p->card($this->id)['work']['approved']['head'];
    commitMain($this->p, 'app.php.md', "Acme Notes\n");
    $this->p->sandbox->ok(['refresh', $this->id]);

    expect($this->p->sandbox->ok(['context', $this->id, '--evaluate']))
        ->toContain('re-verify: approved @'.substr($approved, 0, 7).'; since then only clean merges of main. Run `vendor/bin/kanban gates` and the whole suite; a full review is not needed.');

    $this->p->commit($this->wt, 'more.php');
    expect($this->p->sandbox->ok(['context', $this->id, '--evaluate']))->not->toContain('re-verify');
});

it('never merges main into uncommitted changes', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    file_put_contents($this->wt.'/wip.php', "<?php\n");
    gone($this->p, 'a4d2c0ffee');
    commitMain($this->p, 'other.txt', "other\n");
    $head = trim($this->p->git($this->wt, 'rev-parse', 'HEAD'));

    $one = $this->p->sandbox->kanban(['refresh', $this->id]);
    $all = $this->p->sandbox->kanban(['refresh', '--all']);

    expect($one->getExitCode())->toBe(3)
        ->and($one->getErrorOutput())->toContain("{$this->id}: uncommitted changes in its clone; its worker commits them before main is merged in")
        ->and($one->getErrorOutput())->toContain('wip.php')
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
    $rebuilt = $this->p->in($this->wt, ['rebuild-branch', $this->id]);
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
