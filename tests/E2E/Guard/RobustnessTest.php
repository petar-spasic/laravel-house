<?php

use PetarSpasic\Kanban\Tests\Support\GuardSandbox;

function guardPayload(GuardSandbox $sandbox, string $name): string
{
    $json = file_get_contents(dirname(__DIR__, 2)."/Support/payloads/{$name}.json");

    return strtr($json, array_map(fn (string $path): string => trim(json_encode($path, JSON_UNESCAPED_SLASHES), '"'), [
        '{main}' => $sandbox->main,
        '{wt}' => $sandbox->wt(GuardSandbox::DOING),
        '{outside}' => $sandbox->root.'/outside',
    ]));
}

it('binds then allows a commit for a real Claude Code payload', function () {
    $sandbox = new GuardSandbox;

    expect($sandbox->raw(guardPayload($sandbox, 'worker-enter'))['decision'])->toBe('allow')
        ->and($sandbox->raw(guardPayload($sandbox, 'worker-commit'))['decision'])->toBe('allow');
});

it('fails closed for a subagent and open for the main session on malformed input', function () {
    $sandbox = GuardSandbox::shared();

    $subagent = $sandbox->raw('{"agent_id":"a1","agent_type":"kanban-worker","tool_name":"Bash","tool_input":');
    $main = $sandbox->raw('{"tool_name":"Bash","tool_input":');

    expect($subagent['decision'])->toBe('deny')
        ->and($subagent['reason'])->toStartWith('kanban guard error:')->toContain('ask the main session')
        ->and($main['out'])->toBe('');
});

it('fails closed on a traversal agent_id', function () {
    $result = GuardSandbox::shared()->raw(json_encode([
        'agent_id' => '../../x', 'agent_type' => 'kanban-worker', 'cwd' => GuardSandbox::shared()->main,
        'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'],
    ]));

    expect($result['reason'])->toContain('kanban guard error');
});

it('stays silent outside a git repository', function () {
    $sandbox = GuardSandbox::shared();
    $result = $sandbox->raw(json_encode(['cwd' => '/', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/tmp/x']]));

    expect($result['out'])->toBe('');
});

it('decides within 50 ms at p95', function () {
    $sandbox = GuardSandbox::shared();
    $payload = guardPayload($sandbox, 'worker-commit');
    $sandbox->bind('a4d2c0ffee', 'kanban-worker', GuardSandbox::DOING);

    $times = [];
    for ($run = 0; $run < 200; $run++) {
        $result = $sandbox->raw($payload);
        $times[] = $result['ms'];
    }
    sort($times);
    $p95 = $times[(int) floor(count($times) * 0.95) - 1];

    fwrite(STDERR, sprintf("\n  kanban-guard latency: p50 %.1f ms, p95 %.1f ms\n", $times[99], $p95));

    expect($result['decision'])->toBe('allow')
        ->and($p95)->toBeLessThan(50.0);
});
