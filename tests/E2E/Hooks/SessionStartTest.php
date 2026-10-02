<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

it('prints the brief and exports KANBAN_SESSION into CLAUDE_ENV_FILE', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->enter($wt);
    $blocked = $p->sandbox->card('Waiting on owner');
    $p->sandbox->ok(['set', $blocked, 'blocked=question: one workspace per team?']);
    $next = $p->sandbox->readyCard('Next up', ['--priority=high']);
    $envFile = $p->main.'/.git/claude-env';
    file_put_contents($envFile, "export FOO=1\n");

    $start = $p->hook('session-start', $p->payload('session-start'), ['CLAUDE_ENV_FILE' => $envFile]);

    expect($start->getExitCode())->toBe(0)
        ->and(file_get_contents($envFile))->toBe("export FOO=1\nexport KANBAN_SESSION='".ProtocolSandbox::SESSION."'\n");
    $lines = explode("\n", rtrim($start->getOutput()));
    expect($lines[0])->toMatch('/^Kanban ACME: branch kanban @[0-9a-f]{7}, not published, sync off, \d{4}-\d\d-\d\d \d\d:\d\dZ$/')
        ->and($lines[1])->toBe('WIP doing 1/6, review 0/6 · ready 1 · backlog 1 · blocked 1 · questions 1')
        ->and($lines[2])->toMatch("/^doing  {$id} norm project\/work Conditional clauses: worker a4d2 live \d+s, wt ".basename($wt).'$/')
        ->and($lines[3])->toBe("blocked {$blocked} Waiting on owner: \"question: one workspace per team?\"")
        ->and($lines[4])->toBe("next: {$next} high")
        ->and($lines[5])->toBe('checks: merge driver ok · journal 0 · guard ok · hooksPath ok · 0 orphan worktrees · lease: free')
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
        ->toContain("commits not on main: 1\n  ")
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

it('prunes runtime files nothing reads any more and keeps the rest', function () {
    $p = ProtocolSandbox::create();
    [$id] = $p->started('Conditional clauses');
    $old = time() - 30 * 86400;
    $write = function (string $relative, array $data, int $mtime) use ($p): string {
        $file = $p->runtime($relative);
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($data));
        touch($file, $mtime);

        return $file;
    };
    $stopped = $write('agents/old-stopped.json', ['agent_id' => 'old-stopped', 'stopped_at' => '2026-01-01T00:00:00Z'], $old);
    $liveButQuiet = $write('agents/old-live.json', ['agent_id' => 'old-live', 'stopped_at' => null, 'card' => $id], time() - 60);
    $applied = $write('applied/ACME-OLD.report.abc.json', [], $old);
    $spawn = $write('spawns/ACME-OLD.json', ['card' => 'ACME-OLD'], time() - 900);
    $orphanStaged = $write('staged/ACME-GONE.report.json', [], $old);
    $keptStaged = $write("staged/{$id}.report.json", [], $old);
    $lease = $write('lease.json', ['session' => 'gone', 'since' => '2026-01-01T00:00:00Z'], time() - 3600);

    $p->hook('session-start', $p->payload('session-start'));

    expect($stopped)->not->toBeFile()
        ->and($applied)->not->toBeFile()
        ->and($spawn)->not->toBeFile()
        ->and($orphanStaged)->not->toBeFile()
        ->and($lease)->not->toBeFile()
        ->and($liveButQuiet)->toBeFile()
        ->and($keptStaged)->toBeFile();
});

it('cuts a very long card body so the gates and the protocol line survive', function () {
    $p = ProtocolSandbox::create(['gates' => ['report' => ['php artisan test --compact']]]);
    [$id, $wt] = $p->started('Long body');
    $p->sandbox->ok(['set', $id, 'body=@-', '--force'], ['KANBAN_SESSION' => 's1'], str_repeat("A long paragraph of background the card carries.\n", 400));

    $start = $p->hook('session-start', $p->payload('session-start', ['cwd' => $wt]), cwd: $wt);
    $context = json_decode($start->getOutput(), true)['hookSpecificOutput']['additionalContext'];

    expect(strlen($context))->toBeLessThan(9000)
        ->and($context)->toContain("… cut here; the whole body: `vendor/bin/kanban show {$id}`")
        ->and($context)->toContain("up to 120 s):\n  php artisan test --compact")
        ->and($context)->toContain('protocol: work and commit only in this worktree');
});

it('still prints the brief while the owner is resolving a rebase in the board worktree', function () {
    $p = ProtocolSandbox::create();
    $p->started('Conditional clauses');
    $rebase = Process::fromShellCommandline("GIT_SEQUENCE_EDITOR=\"sed -i '1s/^pick/break/'\" git rebase -i HEAD~1", $p->main.'/docs/kanban');
    $rebase->run();

    $start = $p->hook('session-start', $p->payload('session-start'));

    expect($start->getExitCode())->toBe(0)
        ->and($start->getOutput())->toStartWith('Kanban ACME:')
        ->and($start->getErrorOutput())->toContain('a git rebase is in progress');
});

it('removes dependency-copy leftovers an hour old and keeps a fresh one', function () {
    $p = ProtocolSandbox::create();
    $staging = $p->main.'/.claude/worktrees/.copying';
    mkdir($staging.'/old-node_modules-1/left-pad', 0775, true);
    mkdir($staging.'/new-node_modules-2', 0775, true);
    touch($staging.'/old-node_modules-1', time() - 7200);

    $p->hook('session-start', $p->payload('session-start'));

    expect($staging.'/old-node_modules-1')->not->toBeDirectory()
        ->and($staging.'/new-node_modules-2')->toBeDirectory();
});

it('shows a body of wide characters whole while it is under the limit', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Wide body');
    $body = implode("\n", array_map(fn (int $n) => "行{$n}: これは長い説明です。背景をここに書きます。", range(1, 200)));
    $p->sandbox->ok(['set', $id, 'body=@-', '--force'], ['KANBAN_SESSION' => 's1'], $body);

    $start = $p->hook('session-start', $p->payload('session-start', ['cwd' => $wt]), cwd: $wt);
    $context = json_decode($start->getOutput(), true)['hookSpecificOutput']['additionalContext'];

    expect(mb_strlen($body))->toBeLessThan(6000)
        ->and($context)->toContain('行200: これは長い説明です。')
        ->and($context)->not->toContain('cut here');
});

it('shows a card claimed on another machine as running there, not as agentless', function () {
    $p = ProtocolSandbox::create();
    [$id] = $p->started('Conditional clauses');
    $file = $p->main."/docs/kanban/project/work/{$id}.json";
    $card = json_decode(file_get_contents($file), true);
    $card['work']['host'] = 'alice-laptop';
    $card['claim']['by'] = 'alice@alice-laptop';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT)."\n");

    $lines = explode("\n", $p->hook('session-start', $p->payload('session-start'))->getOutput());

    expect($lines[2])->toContain("doing  {$id}")->toContain('on alice-laptop')->not->toContain('no agent');
});

it('prints the same brief with hundreds of stopped agent records around', function () {
    $p = ProtocolSandbox::create();
    [$id, $wt] = $p->started('Conditional clauses');
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->enter($wt);
    $normalize = fn (string $brief) => preg_replace(['/\d{4}-\d\d-\d\d \d\d:\d\dZ/', '/ live \d+[smhd]/'], ['NOW', ' live AGE'], $brief);
    $plain = $normalize($p->hook('session-start', $p->payload('session-start'))->getOutput());

    @mkdir($p->runtime('agents'), 0775, true);
    foreach (range(1, 300) as $n) {
        file_put_contents($p->runtime("agents/old-{$n}.json"), json_encode(['agent_id' => "old-{$n}", 'agent_type' => 'kanban-worker', 'card' => $id, 'stopped_at' => '2026-01-01T00:00:00Z']));
        touch($p->runtime("agents/old-{$n}.json"), time() - 3600);
    }
    $crowded = $normalize($p->hook('session-start', $p->payload('session-start'))->getOutput());

    expect($crowded)->toBe($plain)->and($plain)->toContain("doing  {$id}")->toContain('worker a4d2 live AGE');
});

it('counts the doing cards of this machine against max_parallel, and names the ones running elsewhere', function () {
    $p = ProtocolSandbox::create();
    [$here] = $p->started('Runs here');
    [$away] = $p->started('Runs on another machine');
    $file = glob($p->main.'/docs/kanban/*/*/'.$away.'.json')[0];
    $card = json_decode(file_get_contents($file), true);
    $card['work']['host'] = 'another-machine';
    $card['claim']['by'] = 'main@another-machine';
    file_put_contents($file, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $p->sandbox->boardGit('commit', '-q', '-am', 'the card runs elsewhere');

    $lines = explode("\n", rtrim($p->hook('session-start', $p->payload('session-start'))->getOutput()));

    expect($lines[1])->toStartWith('WIP doing 1/6 (+1 elsewhere), review 0/6');
});
