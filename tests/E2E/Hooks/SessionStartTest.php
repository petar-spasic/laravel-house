<?php

use PetarSpasic\Kanban\Tests\Support\ProtocolSandbox;
use PetarSpasic\Kanban\Tests\Support\Sandbox;

it('prints the brief and exports KANBAN_SESSION into CLAUDE_ENV_FILE', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->enter($wt);
    $blocked = $p->sandbox->card('Waiting on owner');
    $p->sandbox->ok(['set', $blocked, 'blocked=needs owner decision']);
    $next = $p->sandbox->readyCard('Next up', ['--priority=high']);
    $decided = $p->sandbox->card('Workspace per team', ['--stage=decided', '--decided-on=2026-09-28'], 'project/decisions');
    $envFile = $p->main.'/.git/claude-env';
    file_put_contents($envFile, "export FOO=1\n");

    $start = $p->hook('session-start', $p->payload('session-start'), ['CLAUDE_ENV_FILE' => $envFile]);

    expect($start->getExitCode())->toBe(0)
        ->and(file_get_contents($envFile))->toBe("export FOO=1\nexport KANBAN_SESSION='".ProtocolSandbox::SESSION."'\n");
    $lines = explode("\n", rtrim($start->getOutput()));
    expect($lines[0])->toMatch('/^Kanban ACME: branch kanban @[0-9a-f]{7}, not published, sync off, \d{4}-\d\d-\d\d \d\d:\d\dZ$/')
        ->and($lines[1])->toBe('WIP doing 1/6, review 0/6 · ready 1 · backlog 1 · blocked 1 · proposed decisions 0')
        ->and($lines[2])->toMatch("/^doing  {$id} norm project\/work Conditional clauses: worker a4d2 live \d+s, wt ".strtolower($id).'$/')
        ->and($lines[3])->toBe("blocked {$blocked} Waiting on owner: \"needs owner decision\"")
        ->and($lines[4])->toBe("next: {$next} high")
        ->and($lines[5])->toBe("decided (latest 1): 2026-09-28 {$decided} Workspace per team")
        ->and($lines[6])->toBe('checks: merge driver ok · journal 0 · guard ok · hooksPath ok · 0 orphan worktrees · lease: free')
        ->and(strlen($start->getOutput()))->toBeLessThan(6000);
});

it('gives a session in a card worktree the card context and a session title', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');
    $p->commit($wt, 'app.php', "<?php\n", "{$id}: first step");

    $start = $p->hook('session-start', $p->payload('session-start', ['cwd' => $wt.'/app']), cwd: $wt);
    $json = json_decode($start->getOutput(), true);

    expect($start->getExitCode())->toBe(0)
        ->and($json['hookSpecificOutput']['hookEventName'])->toBe('SessionStart')
        ->and($json['hookSpecificOutput']['sessionTitle'])->toBe("{$id} Conditional clauses")
        ->and($json['hookSpecificOutput']['additionalContext'])
        ->toStartWith("{$id} doing normal feature project/work Conditional clauses\nworktree {$wt} branch card/".strtolower($id).'-conditional-clauses base ')
        ->toContain("acceptance:\n  [ ] 1. It renders\n  [ ] 2. It is tested")
        ->toContain("commits since base: 1\n  ")
        ->toContain("{$id}: first step")
        ->toContain('dirty: none')
        ->toContain("protocol: work and commit only in this worktree; when done `vendor/bin/kanban report {$id} --status=review");
});

it('attaches the board when docs/kanban is missing, and says not installed without a board', function () {
    $p = ProtocolSandbox::create();
    $p->sandbox->git('worktree', 'remove', '--force', 'docs/kanban');
    expect(is_dir($p->main.'/docs/kanban'))->toBeFalse();

    $start = $p->hook('session-start', $p->payload('session-start'));
    expect($start->getExitCode())->toBe(0)
        ->and(is_file($p->main.'/docs/kanban/kanban.json'))->toBeTrue()
        ->and($start->getOutput())->toStartWith('Kanban ACME: ');

    $bare = Sandbox::create('bare');
    $none = $bare->kanban(['hook', 'session-start'], input: json_encode(['session_id' => 's', 'cwd' => $bare->root, 'hook_event_name' => 'SessionStart']));
    expect($none->getExitCode())->toBe(0)
        ->and($none->getOutput())->toBe("kanban: not installed\n");

    $outside = Sandbox::tmp();
    $nowhere = $bare->kanban(['hook', 'session-start'], cwd: $outside, input: json_encode(['session_id' => 's', 'cwd' => $outside]));
    expect($nowhere->getExitCode())->toBe(0)
        ->and($nowhere->getOutput())->toBe("kanban: not installed\n");
});

it('marks agents with a stale heartbeat as stopped', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->enter($wt);
    touch($p->runtime('agents/a4d2c0ffee.json'), time() - 30 * 60);

    $out = $p->hook('session-start', $p->payload('session-start'))->getOutput();

    expect($p->agent('a4d2c0ffee'))->toMatchArray(['card' => $id, 'stop_reason' => 'stale'])
        ->and($p->agent('a4d2c0ffee')['stopped_at'])->not->toBeNull()
        ->and($out)->toContain("doing  {$id} norm project/work Conditional clauses: worker a4d2 stopped, wt ");
});

it('reports orphan card worktrees and the lease holder in the status checks', function () {
    $p = ProtocolSandbox::create();
    $id = $p->sandbox->card('Parked');
    mkdir($p->main.'/.claude/worktrees/'.strtolower($id), 0775, true);
    $p->sandbox->ok(['apply'], ['KANBAN_SESSION' => 'orchestrator-1']);

    expect($p->sandbox->ok('status'))->toContain('· 1 orphan worktrees ('.strtolower($id).') · lease: held by orchestrator-1 (idle ')
        ->and($p->sandbox->ok('status', ['KANBAN_SESSION' => 'orchestrator-1']))->toContain('lease: this session');
});
