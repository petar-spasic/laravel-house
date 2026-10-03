<?php

use PetarSpasic\LaravelHouse\Tests\Support\GuardSandbox;
use Symfony\Component\Process\Process;

it('routes a bound agent\'s shell into its card container, keeping the rest of the input and deciding nothing', function (string $actor, string $card, string $tool) {
    $sandbox = new GuardSandbox;
    $agent = $actor === 'worker' ? 'kanban-worker' : 'kanban-evaluator';
    $sandbox->bind($actor.'-agent', $agent, $card);
    $container = $sandbox->stack($card);
    mkdir($sandbox->wt($card).'/app');
    $input = ['command' => 'php artisan test --filter=Login', 'description' => 'Run the login tests', 'timeout' => 120000, 'run_in_background' => true];

    $result = $sandbox->case($actor, $tool, $input, $sandbox->wt($card).'/app');

    expect($result['decision'])->toBeNull()
        ->and(json_decode($result['out'], true)['hookSpecificOutput']['hookEventName'])->toBe('PreToolUse')
        ->and($result['input'])->toBe([
            'command' => "{$sandbox->main}/vendor/bin/kanban-exec {$container} '{$sandbox->wt($card)}/app' 'php artisan test --filter=Login'",
        ] + $input);
})->with([
    'worker Bash' => ['worker', GuardSandbox::DOING, 'Bash'],
    'worker Monitor' => ['worker', GuardSandbox::DOING, 'Monitor'],
    'evaluator Bash' => ['evaluator', GuardSandbox::REVIEW, 'Bash'],
]);

it("keeps a plain vendor/bin/kanban command on this machine, as main's binary in the card, in any path form", function (string $command, string $rest) {
    $sandbox = GuardSandbox::shared();
    $sandbox->stack(GuardSandbox::DOING);

    $result = $sandbox->case('worker', 'Bash', ['command' => $command], '{wt}');

    expect($result['decision'])->toBeNull()
        ->and($result['input']['command'])->toBe($sandbox->main.'/vendor/bin/kanban --in='.$sandbox->wt(GuardSandbox::DOING).$rest);
})->with([
    'kanban' => ['vendor/bin/kanban report ACME-7K2M9Q --summary=x', ' report ACME-7K2M9Q --summary=x'],
    'kanban by ./' => ['./vendor/bin/kanban stack wait', ' stack wait'],
    'kanban by absolute path' => ['{main}/vendor/bin/kanban context', ' context'],
    "the card's own copy" => ['{wt}/vendor/bin/kanban context', ' context'],
    'kanban through php' => ['php vendor/bin/kanban status', ' status'],
    'operators inside quotes' => ['vendor/bin/kanban report ACME-7K2M9Q --note="a; b | c > d" --verified=\'curl $(x) → 200\'', ' report ACME-7K2M9Q --note="a; b | c > d" --verified=\'curl $(x) → 200\''],
    'a heredoc with a quoted delimiter' => ["vendor/bin/kanban report ACME-7K2M9Q --summary-file=- <<'EOF'\nDone; tests pass | green\nEOF", " report ACME-7K2M9Q --summary-file=- <<'EOF'\nDone; tests pass | green\nEOF"],
]);

it('routes git, and a kanban command chained to anything, into the container', function (string $command) {
    $sandbox = GuardSandbox::shared();
    $sandbox->stack(GuardSandbox::DOING);

    expect($sandbox->case('worker', 'Bash', ['command' => $command], '{wt}')['input']['command'])->toContain('/vendor/bin/kanban-exec ');
})->with([
    'git' => ['git status'],
    'git chained' => ['git add -A && git commit -m "ACME-7K2M9Q: x"'],
    'kanban then something' => ['vendor/bin/kanban context; rm -rf x'],
    'kanban piped' => ['vendor/bin/kanban context | tee out'],
    'kanban in a subshell' => ['vendor/bin/kanban report $(cat id)'],
    'kanban with an env prefix' => ['LD_PRELOAD=x.so vendor/bin/kanban status'],
    'a command substitution in double quotes' => ['vendor/bin/kanban report ACME-7K2M9Q --note="$(id)"'],
    'a heredoc that expands' => ["vendor/bin/kanban report ACME-7K2M9Q --summary-file=- <<EOF\n\$(id)\nEOF"],
    'a command after a heredoc' => ["vendor/bin/kanban report ACME-7K2M9Q --summary-file=- <<'EOF'\nx\nEOF\nrm -rf y"],
]);

it('leaves every other shell alone', function (string $actor, ?string $shell, string $cwd) {
    $sandbox = new GuardSandbox;
    if ($shell !== null) {
        $sandbox->stack(GuardSandbox::DOING, $shell);
    }
    $sandbox->bind('worker-agent', 'kanban-worker', GuardSandbox::DOING);

    expect($sandbox->case($actor, 'Bash', ['command' => 'php artisan test'], $cwd)['out'])->toBe('');
})->with([
    'main' => ['main', 'container', '{wt}'],
    'an unbound worker' => ['worker:w9', 'container', '{wt}'],
    'another subagent' => ['other', 'container', '{wt}'],
    'no stack up' => ['worker', null, '{wt}'],
    'agents.shell host' => ['worker', 'host', '{wt}'],
]);

it('leaves the shell alone without vendor/bin/kanban-exec', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('worker-agent', 'kanban-worker', GuardSandbox::DOING);
    $sandbox->stack(GuardSandbox::DOING);
    unlink($sandbox->main.'/vendor/bin/kanban-exec');

    expect($sandbox->case('worker', 'Bash', ['command' => 'ls'], '{wt}')['out'])->toBe('');
});

it('runs a command from outside its worktree at the worktree root', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('worker-agent', 'kanban-worker', GuardSandbox::DOING);
    $container = $sandbox->stack(GuardSandbox::DOING);

    expect($sandbox->case('worker', 'Bash', ['command' => 'ls'], '{main}')['input']['command'])
        ->toBe("{$sandbox->main}/vendor/bin/kanban-exec {$container} '{$sandbox->wt(GuardSandbox::DOING)}' 'ls'");
});

it('hands the container the command byte for byte', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('worker-agent', 'kanban-worker', GuardSandbox::DOING);
    $container = $sandbox->stack(GuardSandbox::DOING);
    $wt = $sandbox->wt(GuardSandbox::DOING);
    $command = "printf '%s|' \"it's\" '\$HOME' \"\\`echo tick\\`\" \"\$(pwd)\"\necho 'a && b' && echo \"\$((6 * 7))\" | tr 4 X";

    $rewritten = $sandbox->case('worker', 'Bash', ['command' => $command], '{wt}')['input']['command'];
    $docker = $sandbox->root.'/docker';
    $env = ['PATH' => dirname(__DIR__, 2).'/Support/FakeDocker:'.getenv('PATH'), 'FAKE_DOCKER_DIR' => $docker,
        'FAKE_DOCKER_CONTAINERS' => json_encode([$container => [$wt]])];
    $routed = new Process(['bash', '-c', $rewritten], $sandbox->main, $env);
    $routed->run();
    $direct = new Process(['bash', '-c', $command], $wt);
    $direct->run();

    expect($routed->getErrorOutput())->toBe('')
        ->and($routed->getOutput())->toBe($direct->getOutput())
        ->and($routed->getOutput())->toBe("it's|\$HOME|`echo tick`|{$wt}|a && b\nX2\n");
});

it('rewrites within 50 ms at p95', function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('a4d2c0ffee', 'kanban-worker', GuardSandbox::DOING);
    $sandbox->stack(GuardSandbox::DOING);
    $payload = json_decode(file_get_contents(dirname(__DIR__, 2).'/Support/payloads/worker-commit.json'), true);
    $payload['cwd'] = $sandbox->wt(GuardSandbox::DOING);
    $payload['tool_input']['command'] = 'php artisan test; '.$payload['tool_input']['command'];
    $payload = json_encode($payload);
    expect($sandbox->raw($payload)['input']['command'])->toStartWith($sandbox->main.'/vendor/bin/kanban-exec ');

    $times = [];
    for ($run = 0; $run < 200; $run++) {
        $times[] = $sandbox->raw($payload)['ms'];
    }
    sort($times);
    $p95 = $times[(int) floor(count($times) * 0.95) - 1];

    fwrite(STDERR, sprintf("\n  kanban-guard rewrite latency: p50 %.1f ms, p95 %.1f ms\n", $times[99], $p95));

    expect($p95)->toBeLessThan(50.0);
});
