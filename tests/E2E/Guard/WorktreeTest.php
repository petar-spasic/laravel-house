<?php

use PetarSpasic\Kanban\Tests\Support\GuardSandbox;

it('binds a worker to its doing card on EnterWorktree and refuses a second live agent', function () {
    $sandbox = new GuardSandbox;

    $first = $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}']);
    $binding = json_decode(file_get_contents($sandbox->main.'/.git/laravel-kanban/agents/w1.json'), true);

    expect($first['decision'])->toBe('allow')
        ->and($binding)->toMatchArray([
            'agent_id' => 'w1', 'agent_type' => 'kanban-worker', 'card' => GuardSandbox::DOING,
            'worktree' => '.claude/worktrees/acme-7k2m9q', 'stopped_at' => null, 'stop_blocks' => 0,
        ])
        ->and($binding['bound_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00$/');

    $second = $sandbox->case('worker:w2', 'EnterWorktree', ['path' => '{wt}']);
    expect($second['decision'])->toBe('deny')
        ->and($second['reason'])->toContain('live agent w1');

    expect($sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}'])['decision'])->toBe('allow')
        ->and($sandbox->case('worker:w1', 'Edit', ['file_path' => '{wt}/app/A.php'])['decision'])->toBe('allow');
});

it('confines a worker whose EnterWorktree Claude Code refused (guard-only mode, cwd stays main)', function () {
    $sandbox = new GuardSandbox;

    expect($sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}'])['decision'])->toBe('allow')
        ->and($sandbox->case('worker:w1', 'Write', ['file_path' => '{wt}/app/A.php'])['decision'])->toBe('allow')
        ->and($sandbox->case('worker:w1', 'Edit', ['file_path' => 'app/A.php'])['decision'])->toBe('deny')
        ->and($sandbox->case('worker:w1', 'Edit', ['file_path' => '{review}/app/A.php'])['decision'])->toBe('deny')
        ->and($sandbox->case('worker:w1', 'Bash', ['command' => 'cd {wt} && git add app/A.php && git commit -m "ACME-7K2M9Q: a"'])['decision'])->toBe('allow')
        ->and($sandbox->case('worker:w1', 'Bash', ['command' => 'git commit -m x'])['decision'])->toBe('deny')
        ->and($sandbox->case('worker:w1', 'Bash', ['command' => 'php artisan test'])['reason'])->toContain('outside your worktree')
        ->and($sandbox->case('worker:w1', 'Bash', ['command' => 'cd {wt} && vendor/bin/kanban report ACME-7K2M9Q --status=review'])['decision'])->toBe('allow');
});

it('records a pending spawn for a kanban agent, isolation allowed', function () {
    $sandbox = new GuardSandbox;

    $worker = $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-worker', 'isolation' => 'worktree', 'prompt' => 'Card ACME-7K2M9Q. Worktree {wt}']);
    $evaluator = $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-evaluator', 'isolation' => 'worktree', 'prompt' => 'Card ACME-A1B2C3. Worktree {review}']);
    $spawns = $sandbox->main.'/.git/laravel-kanban/spawns';

    expect($worker['decision'])->toBeNull()
        ->and($evaluator['decision'])->toBeNull()
        ->and(json_decode(file_get_contents($spawns.'/ACME-7K2M9Q.json'), true))
        ->toMatchArray(['card' => 'ACME-7K2M9Q', 'agent_type' => 'kanban-worker', 'worktree' => '.claude/worktrees/acme-7k2m9q'])
        ->and(json_decode(file_get_contents($spawns.'/ACME-A1B2C3.json'), true)['agent_type'])->toBe('kanban-evaluator');

    expect($sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-worker', 'prompt' => 'Card ACME-K9M2P1'])['decision'])->toBe('deny')
        ->and(glob($spawns.'/*.json'))->toHaveCount(2);
});

it('lets a new agent take a worktree whose agent is stale or stopped', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('old', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 30);
    $sandbox->bind('evaluator-old', 'kanban-evaluator', GuardSandbox::REVIEW, stoppedAt: '2026-09-28T19:00:00.000+00:00');

    expect($sandbox->case('worker:new', 'EnterWorktree', ['worktree_path' => '{wt}'])['decision'])->toBe('allow')
        ->and($sandbox->case('evaluator:e-new', 'EnterWorktree', ['path' => '{review}'])['decision'])->toBe('allow');
});

it('honours stale_after_minutes from kanban.json', function () {
    $sandbox = new GuardSandbox(['stale_after_minutes' => 60]);
    $sandbox->bind('old', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 30);

    expect($sandbox->case('worker:new', 'EnterWorktree', ['path' => '{wt}'])['decision'])->toBe('deny');
});

it('validates EnterWorktree targets', function (string $actor, array $input, ?string $decision, ?string $reason = null) {
    $result = (new GuardSandbox)->case($actor, 'EnterWorktree', $input);

    expect($result['decision'])->toBe($decision, $result['out']);
    if ($reason !== null) {
        expect($result['reason'])->toContain($reason);
    }
})->with([
    'worker without a path' => ['worker:w', ['name' => 'x'], 'deny', 'ACME-7K2M9Q'],
    'worker on a review card' => ['worker:w', ['path' => '{review}'], 'deny', 'not the worktree of a card in doing'],
    'worker on a random dir' => ['worker:w', ['path' => '{outside}'], 'deny'],
    'evaluator on a doing card' => ['evaluator:e', ['path' => '{wt}'], 'deny', 'in review'],
    'evaluator on its review card' => ['evaluator:e', ['path' => '{review}'], 'allow'],
    'worker by relative path' => ['worker:w', ['path' => '.claude/worktrees/acme-7k2m9q'], 'allow'],
    'other subagent' => ['other', ['path' => '{wt}'], null],
    'main session' => ['main', ['name' => 'scratch'], null],
]);

it('refuses to rebind an agent to a second card', function () {
    $sandbox = new GuardSandbox;
    $sandbox->card('ACME-ZZZZ00', 'doing', '.claude/worktrees/acme-zzzz00');
    mkdir($sandbox->main.'/.claude/worktrees/acme-zzzz00');
    $sandbox->bind('w1', 'kanban-worker', GuardSandbox::DOING);

    $result = $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{main}/.claude/worktrees/acme-zzzz00']);

    expect($result['decision'])->toBe('deny')
        ->and($result['reason'])->toContain('already bound');
});

it('denies ExitWorktree to kanban agents only', function () {
    $sandbox = GuardSandbox::shared();

    expect($sandbox->case('worker', 'ExitWorktree', [], '{wt}')['decision'])->toBe('deny')
        ->and($sandbox->case('evaluator', 'ExitWorktree', [], '{review}')['reason'])->toContain('verdict')
        ->and($sandbox->case('other', 'ExitWorktree', [])['decision'])->toBeNull()
        ->and($sandbox->case('main', 'ExitWorktree', [])['decision'])->toBeNull();
});

it('touches the bound agent heartbeat on every call', function () {
    $sandbox = new GuardSandbox;
    $file = $sandbox->bind('w1', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 30);

    $sandbox->case('worker:w1', 'Bash', ['command' => 'ls'], '{wt}');
    clearstatcache();

    expect(filemtime($file))->toBeGreaterThan(time() - 5);
});

it('validates kanban agent spawns', function (array $input, ?string $decision, ?string $reason = null, bool $liveWorker = false) {
    $sandbox = new GuardSandbox;
    if ($liveWorker) {
        $sandbox->bind('w-live', 'kanban-worker', GuardSandbox::DOING);
    }

    $result = $sandbox->case('main', 'Agent', [...['description' => 'card', 'run_in_background' => true], ...$input]);

    expect($result['decision'])->toBe($decision, $result['out']);
    if ($reason !== null) {
        expect($result['reason'])->toContain($reason);
    }
})->with([
    'worker for the doing card' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Work on ACME-7K2M9Q (depends on ACME-K9M2P1).'], null],
    'worker without a card' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Fix things'], 'deny', 'exactly one card in doing'],
    'worker for a ready card' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Work on ACME-K9M2P1'], 'deny', 'ACME-7K2M9Q'],
    'worker while one is live' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Work on ACME-7K2M9Q'], 'deny', 'SendMessage', true],
    'evaluator for the review card' => [['subagent_type' => 'kanban-evaluator', 'prompt' => 'Evaluate acme-a1b2c3'], null],
    'evaluator for the doing card' => [['subagent_type' => 'kanban-evaluator', 'prompt' => 'Evaluate ACME-7K2M9Q'], 'deny', 'in review'],
    'evaluator while a worker is live elsewhere' => [['subagent_type' => 'kanban-evaluator', 'prompt' => 'Evaluate ACME-A1B2C3'], null, null, true],
    'general-purpose agent' => [['subagent_type' => 'general-purpose', 'prompt' => 'Anything'], null],
]);
