<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
});

function stop(ProtocolSandbox $p, string $wt, string $agent = 'a4d2c0ffee', string $type = 'kanban-worker'): array
{
    $process = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $wt, 'agent' => $agent, 'type' => $type]));

    return ['exit' => $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(),
        'json' => json_decode($process->getOutput(), true)];
}

it('blocks a worker that stops without a report, three times, then marks the card blocked', function () {
    foreach ([1, 2, 3] as $n) {
        $stop = stop($this->p, $this->wt);
        expect($stop['exit'])->toBe(0)
            ->and($stop['json'])->toBe(['decision' => 'block', 'reason' => "No report staged for {$this->id}. Run: vendor/bin/kanban report {$this->id} --status=review|blocked [--tick=N …] --summary-file=- <<'EOF' … EOF (blocked needs --reason=\"…\")"])
            ->and($this->p->agent('a4d2c0ffee')['stop_blocks'])->toBe($n);
    }

    $stop = stop($this->p, $this->wt);
    expect($stop['exit'])->toBe(0)
        ->and($stop['out'])->toBe('')
        ->and($this->p->card($this->id))->toMatchArray(['stage' => 'doing', 'blocked' => 'worker stopped without report'])
        ->and($this->p->agent('a4d2c0ffee')['stopped_at'])->not->toBeNull();
});

it('blocks a review report on a dirty tree, on zero commits and on a failing gate', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();

    $stop = stop($this->p, $this->wt);
    expect($stop['json']['decision'])->toBe('block')
        ->and($stop['json']['reason'])->toContain("Report for {$this->id} not applied. No commits beyond work.base");

    file_put_contents($this->wt.'/app.php', "<?php\n");
    $stop = stop($this->p, $this->wt);
    expect($stop['json']['reason'])->toContain('uncommitted changes')->toContain('?? app.php');

    $this->p->git($this->wt, 'add', 'app.php');
    $this->p->git($this->wt, 'commit', '-q', '-m', "{$this->id}: app");
    $this->p->config(['gates' => ['report' => ['php -r \'for ($i = 1; $i <= 60; $i++) { echo "line $i\n"; } exit(3);\'']]]);
    $stop = stop($this->p, $this->wt);
    expect($stop['json']['reason'])->toContain('Gate failed: `php -r')->toContain('(exit 3)')
        ->toContain('line 60')->toContain('line 21')->not->toContain("line 20\n")
        ->and($this->p->card($this->id)['stage'])->toBe('doing');

    $this->p->config(['gates' => ['report' => ['php -r "exit(0);"']]]);
    expect(stop($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id)['stage'])->toBe('review');
});

it('applies a review report once: ticks, head, discovered cards, applied file, unbound agent', function () {
    $head = $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $report = $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary-file=-',
        '--verified=php artisan test → 3 passed', '--discovered=bug: Footer overlaps — on mobile the footer covers the button'], input: "Built the clauses.\n");
    expect($report->getExitCode())->toBe(0)
        ->and($report->getOutput())->toBe("staged report for {$this->id}: review, head ".substr($head, 0, 7).", ticks 1,2, 1 discovered\napplied when you stop\n");
    $before = count($this->p->sandbox->boardLog());

    $stop = stop($this->p, $this->wt);
    expect($stop['exit'])->toBe(0)->and($stop['out'])->toBe('');

    $card = $this->p->card($this->id);
    $report = array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'report'))[0];
    expect($card['stage'])->toBe('review')
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, true])
        ->and($card['work']['head'])->toBe($head)
        ->and($report)->toMatchArray(['by' => 'worker', 'status' => 'review', 'summary' => 'Built the clauses.', 'ticks' => [1, 2]]);

    $found = $this->p->card($report['discovered'][0]);
    expect($found)->toMatchArray(['type' => 'bug', 'title' => 'Footer overlaps', 'stage' => 'backlog', 'labels' => ['discovered']])
        ->and($found['body'])->toContain("Discovered by {$this->id} (Conditional clauses)")->toContain('on mobile the footer covers the button')
        ->and(glob($this->p->runtime('staged/*')))->toBe([])
        ->and(glob($this->p->runtime("applied/{$this->id}.*.report.json")))->toHaveCount(1)
        ->and($this->p->agent('a4d2c0ffee'))->toMatchArray(['card' => $this->id, 'stop_blocks' => 0])
        ->and($this->p->agent('a4d2c0ffee')['stopped_at'])->not->toBeNull();

    $after = count($this->p->sandbox->boardLog());
    expect($after - $before)->toBe(2);
    expect(stop($this->p, $this->wt))->toMatchArray(['exit' => 0, 'out' => ''])
        ->and(count($this->p->sandbox->boardLog()))->toBe($after);
});

it('applies a blocked report: the card stays in doing with the reason', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Needs a decision on the PDF engine'])->mustRun();

    expect(stop($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id))->toMatchArray(['stage' => 'doing', 'blocked' => 'Needs a decision on the PDF engine']);
});

it('applies an evaluator approval for the branch HEAD and a rejection back to doing', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    stop($this->p, $this->wt);

    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    expect($this->p->agent('e1')['card'])->toBe($this->id);
    $none = stop($this->p, $this->wt, 'e1', 'kanban-evaluator');
    expect($none['json']['reason'])->toStartWith("No verdict staged for {$this->id}.");

    $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:"renders"', '--check=2:fail:"no test for the empty state"', '--issue=TODO left in app.php'])->mustRun();
    expect(stop($this->p, $this->wt, 'e1', 'kanban-evaluator')['out'])->toBe('');
    $card = $this->p->card($this->id);
    $verdict = array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'verdict'))[0];
    expect($card['stage'])->toBe('doing')
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, false])
        ->and(end($card['log']))->toMatchArray(['event' => 'stage', 'from' => 'review', 'to' => 'doing', 'via' => 'reject', 'by' => 'evaluator'])
        ->and($verdict['failed'])->toBe(['2: "no test for the empty state"'])
        ->and($verdict['issues'])->toBe(['TODO left in app.php']);

    $head = $this->p->commit($this->wt, 'tests/EmptyStateTest.php', "<?php\n", "{$this->id}: test");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=2', '--summary=Tested'])->mustRun();
    stop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e2', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e2', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    expect(stop($this->p, $this->wt, 'e2', 'kanban-evaluator')['out'])->toBe('');

    $card = $this->p->card($this->id);
    $base = trim($this->p->git($this->wt, 'merge-base', 'HEAD', 'main'));
    expect($card['stage'])->toBe('review')
        ->and($card['work']['approved'])->toMatchArray(['head' => $head, 'base' => $base])
        ->and($card['work']['approved']['at'])->toMatch('/^\d{4}-\d\d-\d\dT/')
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, true]);
});

it('files an evaluator\'s discovered cards when its approval is applied, once', function () {
    $head = $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done'])->mustRun();
    stop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok',
        '--discovered=bug: Login fails on main — /login answers 500 without this change', '--discovered=Export as CSV'])->mustRun();

    expect(stop($this->p, $this->wt, 'e1', 'kanban-evaluator')['out'])->toBe('');

    $card = $this->p->card($this->id);
    $verdict = array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'verdict'))[0];
    expect($card['work']['approved']['head'])->toBe($head)
        ->and($verdict)->toMatchArray(['by' => 'evaluator', 'decision' => 'approve'])
        ->and($verdict['discovered'])->toHaveCount(2);
    expect($this->p->card($verdict['discovered'][0]))->toMatchArray(['type' => 'bug', 'title' => 'Login fails on main', 'stage' => 'backlog', 'labels' => ['discovered']])
        ->and($this->p->card($verdict['discovered'][0])['body'])->toBe("Discovered by {$this->id} (Conditional clauses) while evaluating it.\n\n/login answers 500 without this change")
        ->and($this->p->card($verdict['discovered'][1]))->toMatchArray(['type' => 'feature', 'title' => 'Export as CSV', 'stage' => 'backlog']);

    $cards = glob($this->p->main.'/docs/kanban/project/work/*-*.json');
    expect(stop($this->p, $this->wt, 'e1', 'kanban-evaluator')['out'])->toBe('')
        ->and($this->p->sandbox->ok(['apply', '--all']))->toBe("nothing staged to apply\n")
        ->and(glob($this->p->main.'/docs/kanban/project/work/*-*.json'))->toBe($cards)->toHaveCount(3);
});

it('refuses an approval whose head is no longer the branch HEAD, filing nothing', function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done'])->mustRun();
    stop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok', '--discovered=bug: Footer overlaps'])->mustRun();
    $moved = $this->p->commit($this->wt, 'more.php');

    $stop = stop($this->p, $this->wt, 'e1', 'kanban-evaluator');
    expect($stop['json']['reason'])->toContain('but the branch is at '.substr($moved, 0, 7))
        ->and($this->p->card($this->id)['work']['approved'])->toBeNull()
        ->and(glob($this->p->main.'/docs/kanban/project/work/*-*.json'))->toHaveCount(1);
});

it('keeps the payload in the inbox when the hook fails and SessionStart retries it', function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $cards = $this->p->main.'/docs/kanban/project/work';
    chmod($cards, 0555);
    try {
        $failed = stop($this->p, $this->wt);
    } finally {
        chmod($cards, 0775);
    }
    expect($failed['exit'])->toBe(1)
        ->and($failed['err'])->toContain('kanban hook subagent-stop failed')
        ->and(glob($this->p->runtime('inbox/*-subagent-stop-*.json')))->toHaveCount(1)
        ->and($this->p->card($this->id)['stage'])->toBe('doing');

    $start = $this->p->hook('session-start', $this->p->payload('session-start'));
    expect($start->getExitCode())->toBe(0)
        ->and(glob($this->p->runtime('inbox/*')))->toBe([])
        ->and($this->p->card($this->id)['stage'])->toBe('review')
        ->and($this->p->agent('a4d2c0ffee')['stopped_at'])->not->toBeNull();
})->skip(function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores the read-only directory the failure relies on');

it('lets non-kanban agents stop untouched', function () {
    $stop = stop($this->p, $this->wt, 'gp1', 'general-purpose');

    expect($stop)->toMatchArray(['exit' => 0, 'out' => ''])
        ->and($this->p->agent('gp1'))->toBeNull()
        ->and(glob($this->p->runtime('inbox/*')))->toBe([]);
});

it('leaves a report staged, and the card alone, when the card is held on another machine or has left the work', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $file = glob($this->p->main.'/docs/kanban/*/*/'.$this->id.'.json')[0];
    $original = json_decode(file_get_contents($file), true);
    $write = function (array $card) use ($file) {
        file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->p->sandbox->boardGit('commit', '-q', '-am', 'changed on another machine');
    };
    $write(array_replace_recursive($original, ['claim' => ['by' => 'alice@another-machine'], 'work' => ['host' => 'another-machine']]));

    $stopped = stop($this->p, $this->wt);

    expect($stopped['json']['decision'] ?? null)->not->toBe('block')
        ->and($stopped['err'])->toContain('stays staged')
        ->and($this->p->card($this->id)['acceptance'][0]['done'])->toBeFalse()
        ->and($this->p->card($this->id)['stage'])->toBe('doing')
        ->and($this->p->card($this->id)['blocked'])->toBeNull();

    $write(array_replace($original, ['stage' => 'ready', 'claim' => null]));
    $applied = $this->p->sandbox->ok(['apply', $this->id]);

    expect($applied)->toContain('stays staged')
        ->and($this->p->card($this->id)['stage'])->toBe('ready');

    $write($original);
    expect($this->p->sandbox->ok(['apply', $this->id]))->toContain('report applied')
        ->and($this->p->card($this->id)['acceptance'][0]['done'])->toBeTrue();
});

it('lets a worker stop without blocking a card that was sent back while it worked', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $file = glob($this->p->main.'/docs/kanban/*/*/'.$this->id.'.json')[0];
    $card = json_decode(file_get_contents($file), true);
    // another person stopped the card: it is ready again and only remembers the parked branch
    $card = array_replace($card, ['stage' => 'ready', 'claim' => null, 'work' => ['parked_branch' => 'card/parked']]);
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $this->p->sandbox->boardGit('commit', '-q', '-am', 'stopped on another machine');

    foreach (range(1, 4) as $stop) {
        $stopped = stop($this->p, $this->wt);
        expect($stopped['json']['decision'] ?? null)->not->toBe('block');
    }

    expect($this->p->card($this->id))->toMatchArray(['stage' => 'ready', 'blocked' => null]);
});

it('does not refuse or block a worker without a report when its card has left the work on another machine', function () {
    $file = glob($this->p->main.'/docs/kanban/*/*/'.$this->id.'.json')[0];
    $card = json_decode(file_get_contents($file), true);
    $card = array_replace($card, ['stage' => 'ready', 'claim' => null, 'work' => ['parked_branch' => 'card/parked']]);
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $this->p->sandbox->boardGit('commit', '-q', '-am', 'stopped on another machine');

    foreach (range(1, 4) as $stop) {
        $stopped = stop($this->p, $this->wt);
        expect($stopped['json']['decision'] ?? null)->not->toBe('block');
    }

    expect($this->p->card($this->id))->toMatchArray(['stage' => 'ready', 'blocked' => null]);
});

it('drops a staged report that is older than the last start of its card instead of applying it to the new attempt', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Attempt one'])->mustRun();
    $file = glob($this->p->main.'/docs/kanban/*/*/'.$this->id.'.json')[0];
    $card = json_decode(file_get_contents($file), true);
    $card['claim']['by'] = 'alice@another-machine';
    $card['work']['host'] = 'another-machine';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $this->p->sandbox->boardGit('commit', '-q', '-am', 'held elsewhere');
    stop($this->p, $this->wt);
    // the card is here again, started anew after the report was staged
    $card = json_decode(file_get_contents(glob($this->p->main.'/docs/kanban/*/*/'.$this->id.'.json')[0]), true);
    $card['claim']['by'] = 'me@'.gethostname();
    $card['work']['host'] = gethostname();
    $card['work']['started'] = gmdate('Y-m-d\TH:i:s', time() + 3600).'.000+00:00';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $this->p->sandbox->boardGit('commit', '-q', '-am', 'started again');

    $applied = $this->p->sandbox->ok(['apply', $this->id]);

    expect($applied)->toContain('predates')
        ->and($this->p->card($this->id)['acceptance'][0]['done'])->toBeFalse()
        ->and($this->p->sandbox->ok(['apply', $this->id]))->toContain('nothing staged');
});

it('shows an applied report as the worker, with the person whose machine applied it', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    stop($this->p, $this->wt);

    $shown = $this->p->sandbox->ok(['show', $this->id, '--log=20']);

    expect($shown)->toContain(' worker (Test User) ')->and($shown)->toContain(' owner (Test User) ');
});

it('lets an evaluator with nothing staged stop once its card has left review', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    stop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');

    $this->p->sandbox->ok(['set', $this->id, 'accept[1]=It renders on mobile', '--reason=the owner asked'], ['KANBAN_SESSION' => 's1']);
    $stop = stop($this->p, $this->wt, 'e1', 'kanban-evaluator');

    expect($stop['out'])->toBe('')
        ->and($stop['err'])->toContain("kanban: {$this->id}: no verdict needed: the card is doing")
        ->and($this->p->agent('e1')['stopped_at'])->not->toBeNull();
});
