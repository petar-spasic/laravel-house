<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    [$this->other, $this->otherWt] = $this->p->started('Other card');
});

it('stages a report only from the card\'s own worktree', function () {
    $main = $this->p->sandbox->kanban(['report', $this->id, '--status=review', '--summary=Done']);
    expect($main->getExitCode())->toBe(3)
        ->and($main->getErrorOutput())->toBe("report runs from {$this->id}'s worktree: cd {$this->wt}\n");

    $wrong = $this->p->in($this->otherWt, ['report', $this->id, '--status=review', '--summary=Done']);
    expect($wrong->getExitCode())->toBe(3);

    mkdir($this->wt.'/app');
    $ok = $this->p->in($this->wt.'/app', ['report', $this->id, '--status=review', '--tick=2', '--tick=1', '--summary=Done', '--verified=pest → 3 passed', '--note=fyi']);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toContain('warning: No commits beyond work.base');

    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.report.json")), true);
    expect(array_keys($staged))->toBe(['card', 'status', 'ticks', 'summary', 'verified', 'discovered', 'upstream', 'reason', 'note', 'head', 'worktree', 'session', 'after', 'staged_at', 'hash'])
        ->and($staged)->toMatchArray(['card' => $this->id, 'status' => 'review', 'ticks' => [1, 2], 'summary' => 'Done', 'verified' => ['pest → 3 passed'],
            'discovered' => [], 'upstream' => [], 'reason' => null, 'note' => 'fyi', 'worktree' => '.claude/worktrees/'.basename($this->wt), 'session' => null, 'after' => null])
        ->and($staged['head'])->toBe(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')))
        ->and($staged['hash'])->toMatch('/^[0-9a-f]{16}$/');
});

it('validates a report against the card', function (array $args, string $error) {
    $process = $this->p->in($this->wt, ['report', $this->id, ...$args]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($error)
        ->and(is_file($this->p->runtime("staged/{$this->id}.report.json")))->toBeFalse();
})->with([
    'unknown criterion' => [['--status=review', '--summary=x', '--tick=3'], 'has no criterion 3 (criteria: 1, 2)'],
    'bad status' => [['--status=done', '--summary=x'], "--status must be review or blocked, not 'done'"],
    'review without summary' => [['--status=review'], 'a review report needs --summary'],
    'blocked without reason' => [['--status=blocked'], 'a blocked report needs --reason'],
    'bad discovered type' => [['--status=review', '--summary=x', '--discovered=idea: Something'], 'type must be one of feature, bug, chore, spike'],
]);

it('takes a follow-up report on a card in review from its worktree, which clears the approval and supersedes a verdict in flight', function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();

    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $head = $this->p->commit($this->wt, 'tests/AppTest.php', "<?php\n");
    $again = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=2', '--summary=Tested too']);
    expect($again->getExitCode())->toBe(0)
        ->and($again->getOutput())->toContain("staged report for {$this->id}: review, head ".substr($head, 0, 7));
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));

    $card = $this->p->card($this->id);
    expect($card)->toMatchArray(['stage' => 'review'])
        ->and($card['work']['head'])->toBe($head)
        ->and($card['work']['approved'])->toBeNull()
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, true]);

    $stop = $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e1', 'type' => 'kanban-evaluator']));
    $card = $this->p->card($this->id);
    expect($stop->getOutput())->toBe('')
        ->and($stop->getErrorOutput())->toContain("{$this->id}: verdict superseded: a report at ")
        ->and($card['work']['approved'])->toBeNull()
        ->and(array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'verdict_superseded'))[0] ?? null)->toMatchArray(['decision' => 'approve', 'by' => 'evaluator'])
        ->and($this->p->agent('e1')['stopped_at'])->not->toBeNull();
});

it('refuses a report for a card that is not in doing or review', function () {
    $this->p->sandbox->ok(['stop', $this->id, '--to=ready', '--reason=Later']);

    $late = $this->p->in($this->p->main, ['report', $this->id, '--status=blocked', '--reason=too late']);

    expect($late->getExitCode())->toBe(3)
        ->and($late->getErrorOutput())->toContain("{$this->id} is ready: only a card in doing or review takes a report");
});

it('stages a verdict only with every criterion covered and a consistent decision', function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));

    $early = $this->p->in($this->otherWt, ['verdict', $this->other, 'approve', '--check=1:pass:ok', '--check=2:pass:ok']);
    expect($early->getExitCode())->toBe(3)->and($early->getErrorOutput())->toContain('is doing, not review');

    $cases = [
        [['approve', '--check=1:pass:ok'], 'every criterion needs a --check; missing: 2'],
        [['approve', '--check=1:pass:ok', '--check=2:fail:broken'], 'approve needs every check passing and no --issue (failing: 2)'],
        [['approve', '--check=1:pass:ok', '--check=2:pass:ok', '--issue=scope creep'], 'approve needs every check passing and no --issue'],
        [['reject', '--check=1:pass:ok', '--check=2:pass:ok'], 'reject needs a failing --check or an --issue'],
        [['reject', '--check=1:maybe:ok'], "--check '1:maybe:ok': use N:pass|fail:\"evidence\""],
        [['reject', '--check=7:fail:x'], '--check 7: '],
        [['maybe'], "the verdict is approve or reject, not 'maybe'"],
        [['approve', '--check=1:pass:ok', '--check=2:pass:ok', '--discovered=idea: Something'], 'type must be one of feature, bug, chore, spike'],
    ];
    foreach ($cases as [$args, $error]) {
        $process = $this->p->in($this->wt, ['verdict', $this->id, ...$args]);
        expect($process->getExitCode())->toBe(2)->and($process->getErrorOutput())->toContain($error);
    }

    $ok = $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:curl shows it', '--check=2:fail:no test',
        '--discovered=bug: Login fails on main — /login answers 500 without this change']);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toMatch("/^staged verdict for {$this->id}: reject at [0-9a-f]{7}, failing 2, 1 discovered\napplied when you stop\n$/");
    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.verdict.json")), true);
    expect($staged)->toMatchArray(['decision' => 'reject', 'checks' => ['1' => ['result' => 'pass', 'evidence' => 'curl shows it'], '2' => ['result' => 'fail', 'evidence' => 'no test']], 'issues' => [],
        'discovered' => [['type' => 'bug', 'title' => 'Login fails on main', 'body' => '/login answers 500 without this change']]])
        ->and($staged['base'])->toBe(trim($this->p->git($this->wt, 'merge-base', 'HEAD', 'main')));
});

it('refuses main-only commands from inside a card worktree', function (array $args) {
    $run = $this->p->in($this->wt, array_map(fn (string $arg) => str_replace('{id}', $this->id, $arg), $args), ['KANBAN_SESSION' => 'session-1']);

    expect($run->getExitCode())->toBe(3, $run->getOutput().$run->getErrorOutput())
        ->and($run->getErrorOutput())->toContain('runs from the main checkout')
        ->and($this->p->card($this->id)['stage'])->toBe('doing');
})->with([
    'move' => [['move', '{id}', 'ready', '--force']],
    'set' => [['set', '{id}', 'priority=high']],
    'stop' => [['stop', '{id}', '--to=ready', '--force']],
    'finish' => [['finish', '{id}']],
    'lease takeover' => [['lease', '--takeover']],
]);

it('lets a card worktree read and report as before', function () {
    $show = $this->p->in($this->wt, ['show', $this->id]);
    $context = $this->p->in($this->wt, ['context']);

    expect($show->getExitCode())->toBe(0)->and($context->getExitCode())->toBe(0);
});

it('warns when a summary is longer than the card keeps, and stages it whole', function () {
    $summary = str_repeat('s', 2001);

    $long = $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--summary='.$summary]);
    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.report.json")), true);
    $short = $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--summary=Short']);

    expect($long->getExitCode())->toBe(0)
        ->and($long->getOutput())->toContain('warning: the summary is 2001 characters; the card keeps the first 2000')
        ->and($staged['summary'])->toBe($summary)
        ->and($short->getOutput())->not->toContain('warning: the summary');
});

it("keeps a verdict's evidence and issues whole for the worker, and refuses one over 2000 characters", function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    $evidence = 'curl /clauses → 500. Acceptable shapes: (a) fix the query '.str_repeat('and keep the index ', 70).'(b) end.';
    $issue = 'app/Clauses.php duplicates the parser: '.str_repeat('reuse it ', 120).'end.';

    $long = $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:'.str_repeat('x', 2001)]);
    expect($long->getExitCode())->toBe(2)
        ->and($long->getErrorOutput())->toContain('--check 2: the evidence is 2001 characters, at most 2000: cut prose, keep the facts');
    $longIssue = $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:pass:ok', '--issue='.str_repeat('y', 2001)]);
    expect($longIssue->getExitCode())->toBe(2)
        ->and($longIssue->getErrorOutput())->toContain('--issue: 2001 characters, at most 2000: cut prose, keep the facts');

    $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:ok', '--check=2:fail:'.$evidence, '--issue='.$issue])->mustRun();
    $this->p->sandbox->ok(['apply', $this->id]);

    $verdict = collect($this->p->card($this->id)['log'])->last(fn (array $e) => $e['event'] === 'verdict');
    expect($verdict['failed'])->toBe(["2: {$evidence}"])
        ->and($verdict['issues'])->toBe([$issue])
        ->and($this->p->in($this->wt, ['context'])->getOutput())->toContain("  2: {$evidence}\n  issue: {$issue}\n");
});
