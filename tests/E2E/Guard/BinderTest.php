<?php

use PetarSpasic\LaravelHouse\Tests\Support\GuardSandbox;

function guardPayload(GuardSandbox $sandbox, string $name): string
{
    $json = file_get_contents(dirname(__DIR__, 2)."/Support/payloads/{$name}.json");

    return strtr($json, array_map(fn (string $path): string => trim(json_encode($path, JSON_UNESCAPED_SLASHES), '"'), [
        '{main}' => $sandbox->main,
        '{wt}' => $sandbox->wt(GuardSandbox::DOING),
        '{outside}' => $sandbox->root.'/outside',
    ]));
}

function agentRecord(GuardSandbox $sandbox, string $agentId): ?array
{
    $file = $sandbox->main.'/.git/laravel-house/agents/'.$agentId.'.json';

    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}

it('binds an agent to its card on EnterWorktree and stays silent', function (string $actor, array $input, ?string $card) {
    $sandbox = new GuardSandbox;

    $result = $sandbox->case($actor, 'EnterWorktree', $input);

    expect($result['out'])->toBe('')
        ->and(agentRecord($sandbox, explode(':', $actor)[1] ?? $actor.'-agent')['card'] ?? null)->toBe($card);
})->with([
    'worker on its doing card' => ['worker:w1', ['path' => '{wt}'], GuardSandbox::DOING],
    'worker by relative path' => ['worker:w1', ['path' => '.claude/worktrees/acme-7k2m9q'], GuardSandbox::DOING],
    'worker by worktree_path' => ['worker:w1', ['worktree_path' => '{wt}'], GuardSandbox::DOING],
    'evaluator on its review card' => ['evaluator:e1', ['path' => '{review}'], GuardSandbox::REVIEW],
    'worker on a review card' => ['worker:w1', ['path' => '{review}'], null],
    'evaluator on a doing card' => ['evaluator:e1', ['path' => '{wt}'], null],
    'worker on a random dir' => ['worker:w1', ['path' => '{outside}'], null],
    'worker without a path' => ['worker:w1', ['name' => 'x'], null],
    'other subagent' => ['other:o1', ['path' => '{wt}'], null],
]);

it('records the binding with the fields the runtime reads', function () {
    $sandbox = new GuardSandbox;
    $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}']);

    expect(agentRecord($sandbox, 'w1'))->toMatchArray([
        'agent_id' => 'w1', 'agent_type' => 'kanban-worker', 'card' => GuardSandbox::DOING,
        'worktree' => '.claude/worktrees/acme-7k2m9q', 'stopped_at' => null, 'stop_blocks' => 0,
    ])->and(agentRecord($sandbox, 'w1')['bound_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00$/');
});

it('keeps an agent on its first card', function () {
    $sandbox = new GuardSandbox;
    $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}']);
    $sandbox->card('ACME-ZZZZ00', 'doing', '.claude/worktrees/acme-zzzz00');
    mkdir($sandbox->main.'/.claude/worktrees/acme-zzzz00');

    $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{main}/.claude/worktrees/acme-zzzz00']);

    expect(agentRecord($sandbox, 'w1')['card'])->toBe(GuardSandbox::DOING);
});

it('does not bind a second agent to a card a live agent already holds', function () {
    $sandbox = new GuardSandbox;
    $sandbox->case('worker:w1', 'EnterWorktree', ['path' => '{wt}']);

    $sandbox->case('worker:w2', 'EnterWorktree', ['path' => '{wt}']);

    expect(agentRecord($sandbox, 'w1')['card'])->toBe(GuardSandbox::DOING)
        ->and(agentRecord($sandbox, 'w2'))->toBeNull();
});

it('binds to a card whose earlier agent stopped or has been silent for minutes', function (?string $stoppedAt, int $ageMinutes) {
    $sandbox = new GuardSandbox;
    $sandbox->bind('old', 'kanban-worker', GuardSandbox::DOING, ageMinutes: $ageMinutes, stoppedAt: $stoppedAt);

    $sandbox->case('worker:new', 'EnterWorktree', ['path' => '{wt}']);

    expect(agentRecord($sandbox, 'new')['card'])->toBe(GuardSandbox::DOING);
})->with([
    'stopped' => ['2026-09-28T19:00:00.000+00:00', 0],
    'silent for ten minutes' => [null, 10],
]);

it('does not bind while the earlier agent beat a minute ago', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('old', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 1);

    $sandbox->case('worker:new', 'EnterWorktree', ['path' => '{wt}']);

    expect(agentRecord($sandbox, 'new'))->toBeNull();
});

it('binds an evaluator to a card a worker of the same card has not stopped yet', function () {
    $sandbox = new GuardSandbox;
    $sandbox->card(GuardSandbox::DOING, 'review', '.claude/worktrees/acme-7k2m9q');
    $sandbox->bind('w-old', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 1);

    $sandbox->case('evaluator:e1', 'EnterWorktree', ['path' => '{wt}']);

    expect(agentRecord($sandbox, 'e1')['card'])->toBe(GuardSandbox::DOING);
});

it('touches the bound agent heartbeat on every call', function () {
    $sandbox = new GuardSandbox;
    $file = $sandbox->bind('w1', 'kanban-worker', GuardSandbox::DOING, ageMinutes: 30);

    $sandbox->case('worker:w1', 'Bash', ['command' => 'ls'], '{wt}');
    clearstatcache();

    expect(filemtime($file))->toBeGreaterThan(time() - 5);
});

it('records a spawn for a kanban agent that names exactly one card in the right stage', function () {
    $sandbox = new GuardSandbox;
    $spawns = $sandbox->main.'/.git/laravel-house/spawns';

    $worker = $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-worker', 'isolation' => 'worktree', 'prompt' => 'Card ACME-7K2M9Q. Worktree {wt}']);
    $evaluator = $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-evaluator', 'prompt' => 'Evaluate acme-a1b2c3']);

    expect($worker['out'])->toBe('')
        ->and($evaluator['out'])->toBe('')
        ->and(json_decode(file_get_contents($spawns.'/ACME-7K2M9Q.json'), true))
        ->toMatchArray(['card' => 'ACME-7K2M9Q', 'agent_type' => 'kanban-worker', 'worktree' => '.claude/worktrees/acme-7k2m9q'])
        ->and(json_decode(file_get_contents($spawns.'/ACME-A1B2C3.json'), true)['agent_type'])->toBe('kanban-evaluator');
});

it('records the spawn for the card a spawn line starts with, though the prompt names another in flight', function () {
    $sandbox = new GuardSandbox;
    $sandbox->card('ACME-ZZZZ00', 'doing', '.claude/worktrees/acme-zzzz00');

    $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-worker', 'prompt' => 'Card ACME-7K2M9Q. Worktree {wt}. ACME-ZZZZ00 touches the same files']);

    expect(array_map('basename', glob($sandbox->main.'/.git/laravel-house/spawns/*.json') ?: []))->toBe(['ACME-7K2M9Q.json']);
});

it('records no spawn for anything else', function (array $input) {
    $sandbox = new GuardSandbox;
    $sandbox->card('ACME-ZZZZ00', 'doing', '.claude/worktrees/acme-zzzz00');

    $sandbox->case('main', 'Agent', $input);

    expect(glob($sandbox->main.'/.git/laravel-house/spawns/*.json') ?: [])->toBe([]);
})->with([
    'no card' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Fix things']],
    'a ready card' => [['subagent_type' => 'kanban-worker', 'prompt' => 'Work on ACME-K9M2P1']],
    'two cards' => [['subagent_type' => 'kanban-worker', 'prompt' => 'ACME-7K2M9Q and ACME-ZZZZ00']],
    'an evaluator for a doing card' => [['subagent_type' => 'kanban-evaluator', 'prompt' => 'Evaluate ACME-7K2M9Q']],
    'another agent type' => [['subagent_type' => 'general-purpose', 'prompt' => 'Work on ACME-7K2M9Q']],
]);

it('decides nothing about a shell command or a file inside the card', function (string $actor, string $tool, array $input) {
    $result = GuardSandbox::shared()->case($actor, $tool, $input, '{wt}');

    expect($result['out'])->toBe('');
})->with([
    'worker pushes' => ['worker', 'Bash', ['command' => 'git push origin main']],
    'worker edits its card' => ['worker', 'Edit', ['file_path' => '{wt}/app/A.php']],
    'main removes the board' => ['main', 'Bash', ['command' => 'rm -rf docs/kanban/project']],
    'main writes anywhere' => ['main', 'Write', ['file_path' => '{board}/kanban.json']],
    'worker exits its worktree' => ['worker', 'ExitWorktree', []],
]);

it("denies a card agent's file tool outside its card, and writes to the card's .git and .claude", function (string $actor, string $tool, array $input) {
    $result = GuardSandbox::shared()->case($actor, $tool, $input, '{wt}');

    expect($result['decision'])->toBe('deny')
        ->and(json_decode($result['out'], true)['hookSpecificOutput']['permissionDecisionReason'])->toStartWith('kanban: ');
})->with([
    'worker writes the board' => ['worker', 'Write', ['file_path' => '{board}/kanban.json']],
    'worker reads main' => ['worker', 'Read', ['file_path' => '{main}/app/A.php']],
    'worker greps main' => ['worker', 'Grep', ['pattern' => 'x', 'path' => '{main}']],
    'evaluator edits another card' => ['evaluator', 'Edit', ['file_path' => '{wt}/app/A.php']],
    'worker writes host tmp' => ['worker', 'Write', ['file_path' => '/tmp/scratch.txt']],
    'worker edits its .git' => ['worker', 'Edit', ['file_path' => '{wt}/.git/config']],
    'worker writes its .claude' => ['worker', 'Write', ['file_path' => '{wt}/.claude/settings.json']],
    "worker reads another project's Claude files" => ['worker', 'Read', ['file_path' => '/tmp/claude-'.posix_getuid().'/-home-someone-else/x/tasks/y.output']],
]);

it("lets a card agent read the skills and Claude Code's own temp directory", function (string $path) {
    $sandbox = GuardSandbox::shared();
    $tmp = sys_get_temp_dir().'/claude-'.posix_getuid().'/'.preg_replace('/[^A-Za-z0-9]/', '-', $sandbox->main);
    $result = $sandbox->case('worker', 'Read', ['file_path' => str_replace('{tmp}', $tmp, $path)], '{wt}');

    expect($result['out'])->toBe('');
})->with([
    'project skills' => ['{main}/.claude/skills/kanban/SKILL.md'],
    'a task output' => ['{tmp}/session/tasks/x.output'],
]);

it('stays silent on malformed input, a traversal agent_id and outside a git repository', function () {
    $sandbox = GuardSandbox::shared();

    expect($sandbox->raw('{"agent_id":"a1","agent_type":"kanban-worker","tool_name":"Bash","tool_input":')['out'])->toBe('')
        ->and($sandbox->raw(json_encode(['agent_id' => '../../x', 'agent_type' => 'kanban-worker', 'cwd' => $sandbox->main, 'tool_name' => 'EnterWorktree', 'tool_input' => ['path' => '{wt}']]))['out'])->toBe('')
        ->and($sandbox->raw(json_encode(['cwd' => '/', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/tmp/x']]))['out'])->toBe('')
        ->and(glob($sandbox->root.'/*.json') ?: [])->toBe([]);
});

it('runs within 50 ms at p95', function () {
    $sandbox = GuardSandbox::shared();
    $payload = guardPayload($sandbox, 'worker-commit');

    $times = [];
    for ($run = 0; $run < 200; $run++) {
        $times[] = $sandbox->raw($payload)['ms'];
    }
    sort($times);
    $p95 = $times[(int) floor(count($times) * 0.95) - 1];

    fwrite(STDERR, sprintf("\n  kanban-guard latency: p50 %.1f ms, p95 %.1f ms\n", $times[99], $p95));

    expect($p95)->toBeLessThan(50.0);
});

it("resolves a card agent's relative file path, or none, in its card rather than its cwd", function (string $tool, array $input, string $key, string $expected) {
    $sandbox = GuardSandbox::shared();
    $result = $sandbox->case('worker', $tool, $input, '{main}');

    expect($result['decision'])->toBeNull()
        ->and($result['input'][$key])->toBe($sandbox->expand($expected));
})->with([
    'Read a relative file' => ['Read', ['file_path' => 'app/A.php'], 'file_path', '{wt}/app/A.php'],
    'Glob without a path' => ['Glob', ['pattern' => '**/*.php'], 'path', '{wt}'],
    'Grep a relative directory' => ['Grep', ['pattern' => 'x', 'path' => 'app'], 'path', '{wt}/app'],
]);
