<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeSettings;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create(['gates' => ['report' => []]]);
    $this->code->defaults = ['FAKE_DOCKER_SERVE' => '1'];
    $this->claude = Sandbox::tmp();
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo "$RANDOM" >> feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" approve --check=1:pass:"feature.txt holds it"
        SH);
    runAgent($this->claude, 'planner', <<<'SH'
        printf '## Files\n- read `README.md` — the app\n\n## Steps\n1. Add feature.txt. Check: `cat feature.txt`\n\n## Criteria\n- 1: `cat feature.txt` holds it\n' > "$WORKTREE/.tmp/plan.md"
        vendor/bin/kanban --in="$WORKTREE" plan "$CARD" --plan-file=.tmp/plan.md
        SH);
});

it('takes a ready card to done: a headless worker, a headless evaluator, then the merge', function () {
    $id = $this->code->sandbox->readyCard('Add login page');

    $first = runPass($this->code, $this->claude);
    $wt = realpath($this->code->worktree($id));
    expect($first)->toContain('driving the board as run:')->toMatch("/ {$id} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');

    $second = runPass($this->code, $this->claude);
    expect($second)->toContain("{$id} worker")->toContain('ended')->toContain("{$id} evaluator")
        ->and($this->code->sandbox->read($id)['work']['approved'])->not->toBeNull();

    $third = runPass($this->code, $this->claude);
    $fourth = runPass($this->code, $this->claude);
    expect($third)->toContain(" {$id} merging\n")
        ->and($fourth)->toContain("merged {$id} into main")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');

    [$worker, $evaluator] = runLaunches($this->claude);
    $session = $worker[array_search('--session-id', $worker, true) + 1];
    $main = realpath($this->code->root());
    expect(array_slice($worker, 0, 17))->toBe(['-p', '--agent', 'kanban-worker', '--model', 'sonnet', '--effort', 'high', '--permission-mode', 'acceptEdits', '--permission-prompts', 'none',
        '--settings', json_encode(['permissions' => ['allow' => ['Bash('.Guard::kanban($main).' *)', ClaudeSettings::execPermission($main)]]], JSON_UNESCAPED_SLASHES),
        '--allowedTools', 'WebFetch,WebSearch', '--output-format', 'json'])
        ->and(end($worker))->toBe("Card {$id}. Worktree {$wt}")
        ->and(array_slice($evaluator, 1, 6))->toBe(['--agent', 'kanban-evaluator', '--model', 'opus', '--effort', 'medium'])
        ->and($session)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    $runs = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($this->code->root().'/.git/laravel-house/runs.jsonl'))));
    expect(array_values($runs)[0])->toMatchArray(['card' => $id, 'type' => 'kanban-worker', 'session' => $session, 'turns' => 3, 'tokens' => 21510, 'cost_usd' => 0.25, 'error' => null])
        ->and(runPass($this->code, $this->claude))->toContain('idle')
        ->and($this->code->ok(['lease']))->toContain('held by run:');
});

it('plans a backlog card with a headless planner, then starts its worker once the plan is on the card', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        vendor/bin/kanban show "$CARD" --plan > "$FAKE_CLAUDE_DIR/plan-read.md"
        SH);
    $id = $this->code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login']);

    $first = runPass($this->code, $this->claude);
    expect($first)->toMatch("/ {$id} planning started; planner [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('planning');

    $second = runPass($this->code, $this->claude);
    expect($second)->toMatch("/ {$id} planner [0-9a-f]{8} ended/")->toContain(" {$id} planned: ready\n")->toMatch("/ {$id} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and(file_get_contents($this->claude.'/plan-read.md'))->toContain("## Criteria\n- 1: `cat feature.txt` holds it\n");

    [$planner, $worker] = runLaunches($this->claude);
    expect(array_slice($planner, 1, 6))->toBe(['--agent', 'kanban-planner', '--model', 'opus', '--effort', 'high'])
        ->and(array_slice($worker, 1, 2))->toBe(['--agent', 'kanban-worker']);
});

it('gives planners only the slots workers leave', function () {
    $board = $this->code->root().'/docs/kanban/kanban.json';
    file_put_contents($board, str_replace('"max_parallel": 6', '"max_parallel": 2', (string) file_get_contents($board)));
    $ready = $this->code->sandbox->readyCard('Ready work');
    $first = $this->code->sandbox->card('Plan first', ['--body=Build it', '--accept=It works', '--label=area:pa', '--priority=high']);
    $second = $this->code->sandbox->card('Plan later', ['--body=Build it', '--accept=It works', '--label=area:pb']);

    $out = runPass($this->code, $this->claude);

    expect($out)->toMatch("/ {$ready} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($out)->toMatch("/ {$first} planning started; planner [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($out)->not->toContain("{$second} planning started")
        ->and(array_column(runLaunches($this->claude), 2))->toBe(['kanban-worker', 'kanban-planner'])
        ->and($this->code->sandbox->read($second))->toMatchArray(['stage' => 'planning', 'claim' => null]);
});

it('parks a card whose planner asks a question in the backlog', function () {
    runAgent($this->claude, 'planner', <<<'SH'
        printf '## Open question\nOne workspace per team, or many?\nExample: Acme has two departments that bill apart.\n1. One — simpler\n2. Many — departments split\nRecommended: 1 — simpler\n' > "$WORKTREE/.tmp/question.md"
        vendor/bin/kanban --in="$WORKTREE" plan "$CARD" --status=blocked --question-file=.tmp/question.md
        SH);
    $id = $this->code->sandbox->card('Add workspaces', ['--body=Build it', '--accept=It works', '--label=area:spaces']);

    runPass($this->code, $this->claude);
    $out = runPass($this->code, $this->claude);

    expect($out)->toContain("{$id} parked in backlog: question: One workspace per team, or many?")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'backlog', 'claim' => null, 'work' => null])
        ->and(runLaunches($this->claude))->toHaveCount(1);
});

it('blocks a card whose planner stops without a plan, and launches it no planner again', function () {
    runAgent($this->claude, 'planner', 'true');
    $id = $this->code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login']);

    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id))->toMatchArray(['stage' => 'planning', 'blocked' => 'planner stopped without a plan'])
        ->and(runLaunches($this->claude))->toHaveCount(1);
});

it('blocks a card after three planner runs that change nothing', function () {
    file_put_contents("{$this->claude}/kanban-planner.error", json_encode(['subtype' => 'error_during_execution']));
    $id = $this->code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login']);

    foreach (range(1, 4) as $n) {
        runPass($this->code, $this->claude);
    }

    expect($this->code->sandbox->read($id)['blocked'])->toBe('kanban run: no progress in 3 agent runs (last: error_during_execution)')
        ->and(array_column(runLaunches($this->claude), 2))->toBe(['kanban-planner', 'kanban-planner', 'kanban-planner']);
});

it('drains without taking a card waiting in planning', function () {
    $id = $this->code->sandbox->card('Waits for a planner', ['--body=Build it', '--accept=It works', '--label=area:wait', '--stage=planning']);

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention']);

    expect($out)->toContain('drained: no card in flight')
        ->and(runLaunches($this->claude))->toBe([])
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'planning', 'claim' => null]);
});

it('launches the agent the claim is for when a card changed stage while the pass started another', function () {
    $first = $this->code->sandbox->readyCard('Add login page');
    $second = $this->code->sandbox->readyCard('Add logout page');
    $run = $this->code->sandbox->start(['run', '--once'], $this->code->env([
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $this->claude, 'FAKE_DOCKER_DELAY' => '2',
    ]));
    $deadline = microtime(true) + 30;
    while ($this->code->sandbox->read($first)['stage'] !== 'doing' && microtime(true) < $deadline) {
        usleep(50_000);
    }
    // while the first card's stack comes up, the owner rewords the second: it goes back to planning
    $this->code->ok(['set', $second, 'accept[1]=It works on a phone']);
    $run->wait();
    runSettled($this->code);
    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention', '--timeout=0']);

    expect(array_slice(array_column(runLaunches($this->claude), 2), 0, 2))->toBe(['kanban-worker', 'kanban-planner'])
        ->and($out)->toContain("{$second} planned: ready")
        ->and($this->code->sandbox->read($second)['plan'])->toContain('## Criteria');
});

it('resumes a planner only under the claim it started with', function () {
    file_put_contents("{$this->claude}/kanban-planner.error", json_encode(['subtype' => 'error_during_execution']));
    $id = $this->code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login']);
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $this->code->ok(['stop', $id, '--to=backlog']);
    unlink("{$this->claude}/kanban-planner.error");

    runPass($this->code, $this->claude);

    $planners = array_values(array_filter(runLaunches($this->claude), fn (array $a) => $a[2] === 'kanban-planner'));
    expect($planners)->toHaveCount(3)
        ->and($planners[1])->toContain('--resume')
        ->and($planners[2])->not->toContain('--resume')
        ->and($this->code->sandbox->read($id)['plan'])->not->toBeNull();
});

it('launches each agent on its configured model and effort, whatever the launching session runs on', function () {
    $this->code->configure(['gates' => ['report' => []], 'agents' => ['worker' => ['model' => 'opus', 'effort' => 'xhigh']]]);
    $this->code->sandbox->readyCard('Add login page');

    runPass($this->code, $this->claude, env: ['CLAUDE_EFFORT' => 'low', 'ANTHROPIC_MODEL' => 'haiku']);

    expect(array_slice(runLaunches($this->claude)[0], 1, 6))->toBe(['--agent', 'kanban-worker', '--model', 'opus', '--effort', 'xhigh'])
        ->and(trim((string) file_get_contents($this->claude.'/env.log')))->toBe('[]');
});

it('resumes the same worker session after a reject', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        if [ -f "$FAKE_CLAUDE_DIR/rejected" ]; then verdict="approve --check=1:pass:ok"; else touch "$FAKE_CLAUDE_DIR/rejected"; verdict="reject --check=1:fail:missing"; fi
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" $verdict
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');

    foreach (range(1, 6) as $n) {
        runPass($this->code, $this->claude);
    }

    $workers = array_values(array_filter(runLaunches($this->claude), fn ($a) => $a[2] === 'kanban-worker'));
    expect($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and($workers)->toHaveCount(2)
        ->and($workers[1])->toContain('--resume')
        ->and($workers[1][array_search('--resume', $workers[1], true) + 1])->toBe($workers[0][array_search('--session-id', $workers[0], true) + 1])
        ->and(end($workers[1]))->toStartWith("Resumed for card {$id}: run vendor/bin/kanban context");

    $session = $workers[0][array_search('--session-id', $workers[0], true) + 1];
    $runs = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($this->code->root().'/.git/laravel-house/runs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
        fn (array $r) => $r['session'] === $session));
    expect($runs)->toHaveCount(2)
        ->and($runs[1])->toMatchArray(['cost_usd' => 0.25, 'session_cost_usd' => 0.5]);
});

it('merges main into a card before resuming its worker, so a fix landed on main reaches it', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        if [ -f "$WORKTREE/fix.txt" ]; then
            cd "$WORKTREE" && echo x > feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
            vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        else
            vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=blocked --reason="the stack lacks a package"
        fi
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id)['blocked'])->toBe('the stack lacks a package');

    $this->code->commitMain('fix.txt', "fixed\n");
    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('never merges main into a clone with uncommitted changes: its worker commits them first', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id)['stage'])->toBe('review');
    $wt = $this->code->worktree($id);
    file_put_contents($wt.'/feature.txt', "left over\n", FILE_APPEND);
    $this->code->commitMain('fix.txt', "fixed\n");

    $back = runPass($this->code, $this->claude);
    expect($back)->toContain("{$id} back to doing: uncommitted changes in its clone")
        ->and($back)->toMatch("/ {$id} worker [0-9a-f]{8} resumed \([a-z]+, [a-z]+\), main not merged: uncommitted changes in its clone\n/")
        ->and(is_file($wt.'/fix.txt'))->toBeFalse()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');

    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    expect(runPass($this->code, $this->claude))->toContain("merged {$id} into main")
        ->and((string) file_get_contents($this->code->root().'/feature.txt'))->toContain("left over\n");
});

it('finishes a start cut short on this machine whose block was cleared, then runs its worker', function () {
    $id = $this->code->started('Add login page');
    $wt = $this->code->worktree($id);
    $this->code->ok(['stack', $id, 'down']);
    (new Process(['rm', '-rf', $wt]))->mustRun();

    $out = runPass($this->code, $this->claude);

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and(is_dir($wt.'/.git'))->toBeTrue()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

/**
 * A ready card of $code, published to $origin, whose `start` exits 9 although its claim reached origin: the push's hook
 * lands the claim commit itself, takes the remote away and fails the push. The remote is back afterwards.
 */
function landedStart(CodeSandbox $code, Origin $origin): string
{
    $code->sandbox->addRemote($origin);
    $code->ok(['sync']);
    $id = $code->sandbox->readyCard('Add login page');
    $code->ok(['sync']);
    $hooks = Sandbox::tmp();
    file_put_contents("{$hooks}/pre-push", implode("\n", [
        '#!/bin/sh', "[ -f \"{$hooks}/done\" ] && exit 0", "touch \"{$hooks}/done\"", 'read ref sha rest',
        "git -C \"{$code->root()}/docs/kanban\" push -q origin \"\$sha:refs/heads/kanban\"",
        "git -C \"{$code->root()}\" remote set-url origin /nonexistent", 'exit 1', '',
    ]));
    chmod("{$hooks}/pre-push", 0755);
    $code->sandbox->git('config', 'core.hooksPath', $hooks);

    $start = $code->kanban(['start', $id], ['KANBAN_SYNC' => 'on']);

    expect($start->getExitCode())->toBe(9, $start->getOutput().$start->getErrorOutput())
        ->and($origin->show("kanban:work/{$id}.json"))->toContain('"stage": "doing"')
        ->and($code->sandbox->read($id)['stage'])->toBe('ready');
    $code->sandbox->git('remote', 'set-url', 'origin', $origin->path);

    return $id;
}

it('finishes a start whose claim reached origin although the start failed, pulling the board itself', function () {
    $id = landedStart($this->code, Origin::create());

    $out = runPass($this->code, $this->claude, env: ['KANBAN_SYNC' => 'on']);

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('finishes, rather than refuses, a start run again whose earlier claim reached origin', function () {
    $id = landedStart($this->code, Origin::create());

    $again = $this->code->kanban(['start', $id], ['KANBAN_SYNC' => 'on', 'KANBAN_SESSION' => 'another-session']);

    expect($again->getExitCode())->toBe(0, $again->getOutput().$again->getErrorOutput())
        ->and($again->getOutput())->toStartWith("resumed {$id}\n")
        ->and($this->code->sandbox->read($id)['work']['stack'])->not->toBeNull()
        ->and(is_dir($this->code->worktree($id).'/.git'))->toBeTrue();
});

it('pulls the board in each pass, so a card readied on another machine starts with nothing else pulling', function () {
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $this->code->ok(['sync']);
    $peer = $origin->clone('peer');
    $peer->ok('attach');
    $id = $peer->readyCard('Readied elsewhere');
    $peer->ok('sync');

    $out = runPass($this->code, $this->claude, env: ['KANBAN_SYNC' => 'on']);

    expect($out)->toMatch("/ {$id} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/");
});

it('leaves the worker of a resumed start alone for the rest of the pass while it runs', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        sleep 2
        cd "$WORKTREE" && echo "$RANDOM" >> feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    $id = $this->code->started('Add login page');
    $this->code->ok(['stack', $id, 'down']);
    (new Process(['rm', '-rf', $this->code->worktree($id)]))->mustRun();

    $out = runPass($this->code, $this->claude);

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")->not->toContain("{$id} blocked")
        ->and($this->code->sandbox->read($id)['blocked'])->toBeNull()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('takes a stale run.pid for no run, whatever process holds that pid now', function () {
    $other = new Process(['bash', '-c', 'exec -a run sleep 30']);
    $other->start();
    usleep(200_000);
    @mkdir($this->code->root().'/.git/laravel-house', 0775, true);
    file_put_contents($this->code->root().'/.git/laravel-house/run.pid', (string) $other->getPid());

    try {
        $out = runPass($this->code, $this->claude);
    } finally {
        $other->stop(0);
    }

    expect($out)->toContain('driving the board as')->not->toContain('already drives');
});

it('leaves a card started from another checkout of this machine alone', function () {
    $id = $this->code->started('Add login page');
    $this->code->ok(['stack', $id, 'down']);
    (new Process(['rm', '-rf', $this->code->worktree($id)]))->mustRun();
    @unlink($this->code->root()."/.git/laravel-house/starts/{$id}");

    $out = runPass($this->code, $this->claude);

    expect($out)->not->toContain("{$id} start resumed")
        ->and(runLaunches($this->claude))->toBe([]);
});

it('finishes a start cut short at the stack cap: the slot it holds is its own', function () {
    $this->code->configure(['gates' => ['report' => []], 'stack' => ['max_stacks' => 1]]);
    $id = $this->code->started('Add login page');
    (new Process(['rm', '-rf', $this->code->worktree($id)]))->mustRun();

    $out = runPass($this->code, $this->claude);

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('takes a start mark for a claim made since for no start of this checkout', function () {
    $id = $this->code->started('Add login page');
    $mark = $this->code->root()."/.git/laravel-house/starts/{$id}";
    $old = (string) file_get_contents($mark);
    $this->code->ok(['stop', $id, '--to=ready', '--force']);
    sleep(1);
    $this->code->ok(['start', $id]);
    $this->code->ok(['stack', $id, 'down']);
    (new Process(['rm', '-rf', $this->code->worktree($id)]))->mustRun();
    file_put_contents($mark, $old);

    expect(runPass($this->code, $this->claude))->not->toContain("{$id} start resumed")
        ->and(runLaunches($this->claude))->toBe([]);
});

it('lets a child command a stopped run started finish its work', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    $env = $this->code->env(['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $this->claude, 'FAKE_DOCKER_DELAY' => '2']);
    $live = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run'], $this->code->root(), $env);
    $live->start();
    $deadline = microtime(true) + 20;
    while (($this->code->sandbox->read($id)['stage'] ?? null) !== 'doing' && microtime(true) < $deadline) {
        usleep(100_000);
    }
    $live->stop(5);
    $deadline = microtime(true) + 30;
    while (($this->code->sandbox->read($id)['work']['stack'] ?? null) === null && microtime(true) < $deadline) {
        usleep(200_000);
    }

    expect($this->code->sandbox->read($id)['work']['stack'])->not->toBeNull()
        ->and($this->code->ok(['drain']))->toStartWith('drain on: the next `kanban run`');
});

it('refuses a run while another holds the run lock, started at the same moment or not', function () {
    @mkdir($this->code->root().'/.git/laravel-house', 0775, true);
    $lock = fopen($this->code->root().'/.git/laravel-house/run.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $second = $this->code->kanban(['run', '--once'], ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]);
    } finally {
        flock($lock, LOCK_UN);
    }

    expect($second->getExitCode())->toBe(3)
        ->and($second->getErrorOutput())->toContain('kanban run already drives this checkout');
});

it('frees the lease of a session that ends even while its own kanban run drives the board', function () {
    $env = $this->code->env(['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $this->claude, 'KANBAN_SESSION' => 's1']);
    $live = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run'], $this->code->root(), $env);
    $live->start();
    $deadline = microtime(true) + 20;
    while (! is_file($this->code->root().'/.git/laravel-house/run.pid') && microtime(true) < $deadline) {
        usleep(50_000);
    }
    try {
        $this->code->sandbox->kanban(['hook', 'session-end'], [], null, json_encode(['session_id' => 's1', 'cwd' => $this->code->root(), 'hook_event_name' => 'SessionEnd', 'reason' => 'clear']))->mustRun();
        $lease = $this->code->ok(['lease']);
    } finally {
        $live->stop(5);
    }

    expect($lease)->toContain('lease: free');
});

it('evaluates a review card whose clone holds only untracked leftovers, and finishes it', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    file_put_contents($this->code->worktree($id).'/screenshot.png', "png\n");

    $evaluated = runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $finished = runPass($this->code, $this->claude);

    expect($evaluated)->not->toContain('back to doing')->toContain("{$id} evaluator")
        ->and($finished)->toContain("merged {$id} into main")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('merges main into a resumed worker whose clone holds only untracked files', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        if [ -f "$FAKE_CLAUDE_DIR/rejected" ]; then verdict="approve --check=1:pass:ok"; else touch "$FAKE_CLAUDE_DIR/rejected"; verdict="reject --check=1:fail:missing"; echo png > "$WORKTREE/screenshot.png"; fi
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" $verdict
        SH);
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo "$RANDOM" >> feature.txt && git add feature.txt && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $this->code->commitMain('fix.txt', "fixed\n");

    $out = runPass($this->code, $this->claude);

    expect($out)->toMatch("/ {$id} worker [0-9a-f]{8} resumed \([a-z]+, [a-z]+\)\n/")
        ->and(is_file($this->code->worktree($id).'/fix.txt'))->toBeTrue();
});

it('says the stack cap is why nothing starts, and frees a slot a start left behind without its card', function () {
    $this->code->configure(['gates' => ['report' => []], 'stack' => ['max_stacks' => 1]]);
    $left = $this->code->started('Left a slot');
    $registry = (string) file_get_contents($this->code->state.'/stacks.json');
    $this->code->ok(['stop', $left, '--to=ready']);
    $blocked = $this->code->started('Holds the slot');
    $this->code->sandbox->ok(['set', $blocked, 'blocked=waiting on the design']);

    $full = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    expect($full)->toContain('stack.max_stacks')->toContain('kanban stack gc')->not->toContain('plan or promote cards');

    $this->code->ok(['stop', $blocked, '--to=backlog']);
    $data = json_decode($registry, true);
    foreach ($data['stacks'] as &$entry) {
        $entry['created_at'] = '2026-01-01T00:00:00.000+00:00';
    }
    file_put_contents($this->code->state.'/stacks.json', json_encode($data));

    $project = array_values($data['stacks'])[0]['project'];
    $freed = runPass($this->code, $this->claude);
    expect($freed)->toContain("freed: {$project} down")->toMatch("/ {$left} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->calls())->toContain("compose -p {$project} down -v --remove-orphans");
});

it('finishes a start whose clone was made but whose stack never came up, instead of sending a worker into no container', function () {
    $id = $this->code->started('Add login page');
    $file = glob($this->code->root().'/docs/kanban/*/'.$id.'.json')[0];
    $card = json_decode((string) file_get_contents($file), true);
    $card['work']['stack'] = null;
    Json::write($file, Json::encode($card, 'card'));
    $this->code->sandbox->boardGit('commit', '-q', '-am', "{$id} stack lost (test)");

    expect(runPass($this->code, $this->claude))->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/");
});

it('drains only once a start of this checkout cut short for want of a slot is finished too', function () {
    $this->code->configure(['gates' => ['report' => []], 'stack' => ['max_stacks' => 1]]);
    $lost = $this->code->started('Lost its slot');
    $this->code->ok(['stack', $lost, 'down']);
    (new Process(['rm', '-rf', $this->code->worktree($lost)]))->mustRun();
    $this->code->sandbox->ok(['set', $lost, 'blocked=start failed: no stack slot: 1 stacks registered on this machine (stack.max_stacks)']);
    $other = $this->code->started('Holds the slot');
    $this->code->sandbox->ok(['set', $other, 'blocked=waiting on the design']);

    expect(runPass($this->code, $this->claude, ['--drain', '--until-attention', '--timeout=0']))->not->toContain('drained');
});

it('retries a start that lost its stack slot after the claim once a slot is free, before any new start', function () {
    $this->code->configure(['gates' => ['report' => []], 'stack' => ['max_stacks' => 1]]);
    $lost = $this->code->started('Lost its slot');
    $wt = $this->code->worktree($lost);
    $this->code->ok(['stack', $lost, 'down']);
    (new Process(['rm', '-rf', $wt]))->mustRun();
    $this->code->sandbox->ok(['set', $lost, 'blocked=start failed: no stack slot: 1 stacks registered on this machine (stack.max_stacks)']);
    $other = $this->code->started('Holds the slot');
    $next = $this->code->sandbox->readyCard('Waits its turn');

    $full = runPass($this->code, $this->claude);
    expect($full)->not->toContain("{$lost} start")->not->toContain("{$next} started")
        ->and($this->code->sandbox->read($lost)['stage'])->toBe('doing');

    $this->code->ok(['stack', $other, 'down']);
    $free = runPass($this->code, $this->claude);

    expect($free)->toMatch("/ {$lost} start resumed; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")->not->toContain("{$next} started")
        ->and($this->code->sandbox->read($lost)['blocked'])->toBeNull()
        ->and($this->code->sandbox->read($lost)['stage'])->toBe('review');
});

it('parks a card whose worker asks a question in backlog, branch kept', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo x > feature.txt && git add -A && git commit -qm "$CARD: half" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=blocked --reason="question: one workspace per team?" --note="Half built"
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');

    runPass($this->code, $this->claude);
    $out = runPass($this->code, $this->claude);

    expect($out)->toContain("{$id} parked in backlog")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'backlog', 'blocked' => 'question: one workspace per team?'])
        ->and(runLaunches($this->claude))->toHaveCount(1);
});

it('plans an answered question card on its kept branch and starts it ahead of new cards, in a drain too', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        if [ -f "$FAKE_CLAUDE_DIR/asked" ]; then
            cd "$WORKTREE" && echo done >> feature.txt && git add -A && git commit -qm "$CARD: rest" && cd - > /dev/null
            vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        else
            touch "$FAKE_CLAUDE_DIR/asked"
            cd "$WORKTREE" && echo half > feature.txt && git add -A && git commit -qm "$CARD: half" && cd - > /dev/null
            vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=blocked --reason="question: one workspace per team?"
        fi
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id)['stage'])->toBe('backlog');
    $board = $this->code->root().'/docs/kanban/kanban.json';
    file_put_contents($board, str_replace('"max_parallel": 6', '"max_parallel": 1', (string) file_get_contents($board)));
    $fresh = $this->code->sandbox->readyCard('Urgent new work', ['--priority=high']);

    expect($this->code->sandbox->ok(['answer', $id, '--note=One per team.']))->toContain("promoted {$id} to planning: its parked branch card/");
    $planned = runPass($this->code, $this->claude, ['--drain', '--once']);
    $out = runPass($this->code, $this->claude, ['--drain', '--once']);

    expect($planned)->toMatch("/ {$id} planning started; planner [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($out)->toContain("{$id} planned: ready")
        ->and($out)->toMatch("/ {$id} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review')
        ->and(file_get_contents($this->code->worktree($id).'/feature.txt'))->toBe("half\ndone\n")
        ->and($this->code->sandbox->read($fresh)['stage'])->toBe('ready');
});

it('drains on `kanban drain`, in the runs after it too, until one has drained', function () {
    $id = $this->code->sandbox->readyCard('Add login page');

    expect($this->code->ok(['drain']))->toBe("drain on: the next `kanban run` starts no new card and returns once none is in flight\n");
    $out = runPass($this->code, $this->claude, ['--until-attention']);

    expect($out)->toContain('draining: `kanban drain` asked for it')->toContain('drained: no card in flight')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('ready')
        ->and(is_file($this->code->root().'/.git/laravel-house/run.drain'))->toBeFalse()
        ->and(runPass($this->code, $this->claude))->toMatch("/ {$id} started; worker [0-9a-f]{8} launched \([a-z]+, [a-z]+\)\n/");
});

it('starts a run while another process only looks at the run lock', function () {
    @mkdir($this->code->root().'/.git/laravel-house', 0775, true);
    $lock = fopen($this->code->root().'/.git/laravel-house/run.lock', 'c');
    flock($lock, LOCK_SH);
    $run = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run', '--once'], $this->code->root(),
        $this->code->env(['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]));
    $run->start();
    usleep(300_000);
    flock($lock, LOCK_UN);
    $run->wait();

    expect($run->getExitCode())->toBe(0);
});

it('lets one run drive a checkout, and turns a live one into a drain without stopping it', function () {
    $env = $this->code->env(['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]);
    $live = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run'], $this->code->root(), $env);
    $live->start();
    $pid = $this->code->root().'/.git/laravel-house/run.pid';
    $deadline = microtime(true) + 20;
    while (! is_file($pid) && $live->isRunning() && microtime(true) < $deadline) {
        usleep(50_000);
    }

    try {
        $second = $this->code->kanban(['run', '--once', '--drain'], $env);
        expect($second->getExitCode())->toBe(3)
            ->and($second->getErrorOutput())->toContain('kanban run already drives this checkout (pid '.trim((string) file_get_contents($pid)).'): `kanban drain` makes it drain')
            ->and($this->code->ok(['drain']))->toBe('drain on: kanban run (pid '.trim((string) file_get_contents($pid)).") starts no new card from its next pass and returns once none is in flight\n");
    } finally {
        $live->stop(5);
    }
    expect($this->code->ok(['drain']))->toStartWith('drain on: the next `kanban run`')
        ->and($this->code->ok(['drain', '--off']))->toBe("drain off: kanban run starts new cards again\n")
        ->and(is_file($this->code->root().'/.git/laravel-house/run.drain'))->toBeFalse();
});

it('blocks a card after three agent runs that change nothing', function () {
    file_put_contents("{$this->claude}/kanban-worker.error", json_encode(['subtype' => 'error_during_execution']));
    $id = $this->code->sandbox->readyCard('Add login page');

    foreach (range(1, 4) as $n) {
        $out = runPass($this->code, $this->claude);
    }

    expect($this->code->sandbox->read($id)['blocked'])->toBe('kanban run: no progress in 3 agent runs (last: error_during_execution)')
        ->and(runLaunches($this->claude))->toHaveCount(3)
        ->and($out)->toContain("{$id} blocked");
});

it('ends a worker that never reports through the stop gate: the card is blocked and not relaunched', function () {
    runAgent($this->claude, 'worker', 'true');
    $id = $this->code->sandbox->readyCard('Add login page');

    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id)['blocked'])->toBe('worker stopped without report')
        ->and(runLaunches($this->claude))->toHaveCount(1);
});

it('stops launching after a usage limit until the pause ends', function () {
    file_put_contents("{$this->claude}/kanban-worker.error", json_encode(['api_error_status' => 429, 'result' => "You've hit your limit"]));
    $id = $this->code->sandbox->readyCard('Add login page');

    runPass($this->code, $this->claude);
    $out = runPass($this->code, $this->claude);

    expect($out)->toContain('paused until')
        ->and(runLaunches($this->claude))->toHaveCount(1)
        ->and($this->code->sandbox->read($id)['blocked'] ?? null)->toBeNull();
});

it('refuses to run without card containers', function () {
    $this->code->configure(['gates' => ['report' => []], 'agents' => ['shell' => 'host']]);

    $process = $this->code->kanban(['run', '--once'], ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.getenv('PATH')]);

    expect($process->getExitCode())->toBe(3)
        ->and($process->getErrorOutput())->toContain('kanban run needs card containers');
});

it('hands back to the orchestrator once what needs judgment: a card blocked without a question', function () {
    runAgent($this->claude, 'worker', 'vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=blocked --reason="the stack lacks a package"');
    $id = $this->code->sandbox->readyCard('Add login page');

    $first = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $second = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $third = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($first)->toContain("{$id} started; worker")->toContain('nothing needs you: run it again')->not->toContain('attention:')
        ->and($second)->toContain("attention:\n")->toContain("\n  {$id} blocked: the stack lacks a package\n")
        ->and($third)->not->toContain("{$id} blocked");
});

it('drives the board as the orchestrating session when one runs it, under its lease', function () {
    $this->code->sandbox->readyCard('Add login page');

    runPass($this->code, $this->claude, ['--once'], ['KANBAN_SESSION' => 's1']);

    expect($this->code->ok(['lease'], ['KANBAN_SESSION' => 's1']))->toContain('this session');
});

it('drains: starts no new card and returns once none is in flight', function () {
    $id = $this->code->sandbox->readyCard('Add login page');

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention']);

    expect($out)->toContain('drained: no card in flight')
        ->and((string) file_get_contents($this->code->root().'/.git/laravel-house/run.log'))->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d drained: no card in flight$/m')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('ready')
        ->and(runLaunches($this->claude))->toBe([]);
});

/** Starts `kanban run --once` and returns once the agent it launched for $id has written $file: the agent is live. */
function liveAgent(CodeSandbox $code, string $claude, string $file): array
{
    $code->kanban(['run', '--once'], [
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $claude,
    ]);
    $deadline = microtime(true) + 30;
    while (! is_file($file) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $runs = glob($code->root().'/.git/laravel-house/runs/*.pid') ?: [];

    return [(int) (json_decode((string) file_get_contents($runs[0] ?? '/dev/null'), true)['pid'] ?? 0), (int) @file_get_contents($file)];
}

function processAlive(int $pid): bool
{
    $stat = @file_get_contents("/proc/{$pid}/stat");

    return $pid > 0 && is_string($stat) && preg_match('/^\d+ \(.*\) [^Z] /s', $stat) === 1;
}

it('ends the headless agent of a card it stops and the commands it runs in sessions of their own, before the clone comes down', function () {
    // Claude Code starts each shell in a session of its own, as setsid does here
    runAgent($this->claude, 'worker', 'setsid sleep 300 & echo $! > "$FAKE_CLAUDE_DIR/child.pid"; wait');
    $id = $this->code->sandbox->readyCard('Add login page');
    [$agent, $child] = liveAgent($this->code, $this->claude, $this->claude.'/child.pid');
    expect(processAlive($agent))->toBeTrue()->and(processAlive($child))->toBeTrue()
        ->and(posix_getpgid($child))->not->toBe(posix_getpgid($agent));

    $out = $this->code->ok(['stop', $id, '--to=backlog']);

    expect($out)->toMatch('/^stopped its worker [0-9a-f]{8}\n/')
        ->and(processAlive($agent))->toBeFalse()
        ->and(processAlive($child))->toBeFalse()
        ->and(glob($this->code->root().'/.git/laravel-house/runs/*.pid') ?: [])->toBe([])
        // `wait` and finish see the agent stopped at once, with no Stop hook of its own
        ->and(array_column(array_map(fn ($f) => json_decode(file_get_contents($f), true), glob($this->code->root().'/.git/laravel-house/agents/*.json') ?: []), 'stopped_at', 'card'))
        ->toHaveKey($id)->not->toContain(null)
        ->and($this->code->sandbox->read($id)['stage'])->toBe('backlog');
});

it('launches nothing for a card being stopped, keeps the mark when the stop fails after its agent ended, and clears it once stopped', function () {
    $id = $this->code->started('Add login page');
    $other = $this->code->started('Add logout page');
    @mkdir($this->code->root().'/.git/laravel-house/stopping', 0775, true);
    file_put_contents($this->code->root()."/.git/laravel-house/stopping/{$id}", '1');
    $launched = fn () => array_map(fn (array $argv) => preg_replace('/^(?:Card|Resumed for card) ([A-Z0-9]+-[A-Z0-9]+).*$/s', '$1', (string) end($argv)), runLaunches($this->claude));

    runPass($this->code, $this->claude);
    expect($launched())->toBe([$other]);

    unlink($this->code->root()."/.git/laravel-house/stopping/{$id}");
    $wt = $this->code->worktree($id);
    file_put_contents($wt.'/wip.txt', "half\n");
    expect($this->code->kanban(['stop', $id, '--to=backlog'])->getExitCode())->toBe(3)
        ->and(glob($this->code->root().'/.git/laravel-house/stopping/*') ?: [])->toBe([]);
    unlink($wt.'/wip.txt');
    $failed = $this->code->kanban(['stop', $id, '--to=backlog'], ['FAKE_DOCKER_FAIL' => 'down']);
    expect($failed->getExitCode())->toBe(7)
        ->and($failed->getErrorOutput())->toContain("{$id} not stopped: its agent is ended, and `kanban run` launches nothing for it for 10 min")
        ->and(is_file($this->code->root()."/.git/laravel-house/stopping/{$id}"))->toBeTrue();
    runPass($this->code, $this->claude);
    expect($launched())->not->toContain($id);
    $this->code->ok(['stop', $id, '--to=backlog']);

    expect(glob($this->code->root().'/.git/laravel-house/stopping/*') ?: [])->toBe([])
        ->and($this->code->sandbox->read($id)['stage'])->toBe('backlog');
});

it('refuses a stop when what the ended agent left in the clone is uncommitted, keeping the mark', function () {
    runAgent($this->claude, 'worker', 'trap \'echo half > "$WORKTREE/wip.txt"; exit 0\' TERM; touch "$FAKE_CLAUDE_DIR/child.pid"; sleep 300 & wait');
    $id = $this->code->sandbox->readyCard('Add login page');
    [$agent] = liveAgent($this->code, $this->claude, $this->claude.'/child.pid');

    $refused = $this->code->kanban(['stop', $id, '--to=backlog']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getOutput())->toMatch('/^stopped its worker [0-9a-f]{8}\n/')
        ->and($refused->getErrorOutput())->toContain('has uncommitted changes')->toContain("{$id} not stopped: its agent is ended")
        ->and(processAlive($agent))->toBeFalse()
        ->and(is_file($this->code->root()."/.git/laravel-house/stopping/{$id}"))->toBeTrue()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('doing');
});

it('ends an agent launched while a stop marked its card, and the pass goes on', function () {
    runAgent($this->claude, 'worker', 'sleep 300');
    $id = $this->code->sandbox->readyCard('Add login page');
    $mark = $this->code->root()."/.git/laravel-house/stopping/{$id}";
    // a stop that marks the card while the launch reads the agents' settings, after its first look at the mark
    $config = file_get_contents($this->code->root().'/config/kanban.php');
    file_put_contents($this->code->root().'/config/kanban.php', str_replace("<?php\n", "<?php\n\nforeach (debug_backtrace() as \$frame) {\n    if ((\$frame['function'] ?? '') === 'settings' && str_ends_with(\$frame['class'] ?? '', 'AgentRun')) {\n        @mkdir(dirname('{$mark}'), 0775, true);\n        touch('{$mark}');\n    }\n}\n", $config));

    $out = runPass($this->code, $this->claude);

    $runs = array_map(fn ($l) => json_decode($l, true), file($this->code->root().'/.git/laravel-house/runs.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
    expect($out)->toContain("{$id} worker not launched: {$id} is being stopped: its agent was ended as it launched")
        ->and(glob($this->code->root().'/.git/laravel-house/runs/*.pid') ?: [])->toBe([])
        ->and(array_column($runs, 'card'))->toBe([$id])
        // the bracket keeps pgrep from finding its own shell
        ->and(shell_exec('pgrep -f '.escapeshellarg('['.$runs[0]['session'][0].']'.substr($runs[0]['session'], 1))))->toBeNull();
});

it('never signals a process that took over the pid of a run of the card it stops', function () {
    $id = $this->code->started('Add login page');
    $other = new Process(['sleep', '300']);
    $other->start();
    @mkdir($this->code->root().'/.git/laravel-house/runs', 0775, true);
    file_put_contents($this->code->root().'/.git/laravel-house/runs/0b7c4a52-9d1e-4f3a-8c2b-5e6f7a8b9c0d.pid',
        json_encode(['pid' => $other->getPid(), 'card' => $id, 'type' => 'kanban-worker', 'stage' => 'doing']));

    try {
        $out = $this->code->ok(['stop', $id, '--to=backlog']);
        $alive = $other->isRunning();
    } finally {
        $other->stop(0);
    }

    expect($alive)->toBeTrue()
        ->and($out)->not->toContain('stopped its')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('backlog');
});

it('refuses to stop a card whose live planner staged a plan not yet applied, unless forced', function () {
    runAgent($this->claude, 'planner', <<<'SH'
        printf '## Files\n- read `README.md` — the app\n\n## Steps\n1. Add feature.txt. Check: `cat feature.txt`\n\n## Criteria\n- 1: `cat feature.txt` holds it\n' > "$WORKTREE/.tmp/plan.md"
        vendor/bin/kanban --in="$WORKTREE" plan "$CARD" --plan-file=.tmp/plan.md
        setsid sleep 300 & echo $! > "$FAKE_CLAUDE_DIR/child.pid"; wait
        SH);
    $id = $this->code->sandbox->card('Add login page', ['--body=Build it', '--accept=It works', '--label=area:login']);
    [$agent, $child] = liveAgent($this->code, $this->claude, $this->claude.'/child.pid');

    $refused = $this->code->kanban(['stop', $id, '--to=backlog']);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$id}: its planner staged a plan not yet applied")
        ->and(processAlive($agent))->toBeTrue();

    $this->code->ok(['stop', $id, '--to=backlog', '--force']);
    expect(processAlive($agent))->toBeFalse()
        ->and(processAlive($child))->toBeFalse()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('backlog');
});

it('never restarts in a drain a card the main session or the owner stopped with its branch parked', function () {
    $id = $this->code->started('Add login page');
    $this->code->commit($id, 'feature.txt', "half\n");
    $this->code->ok(['stop', $id, '--to=ready']);
    expect($this->code->sandbox->read($id))->toMatchArray(['stage' => 'planning', 'claim' => null]);

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention']);

    expect($out)->toContain('drained: no card in flight')
        ->and(runLaunches($this->claude))->toBe([])
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'planning', 'claim' => null]);
});

it('leaves a merged card done and unblocked when a step after the merge fails, and says so', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['after' => ["echo 'seeder: layout off by 0.6%' >&2; exit 1"]]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'after');
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->toContain("merged {$id} into main")
        ->toContain("attention:\n  {$id} merged, then: after: echo 'seeder: layout off by 0.6%' >&2; exit 1 failed (exit 1): seeder: layout off by 0.6%\n")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'done', 'blocked' => null]);
});

it('raises a branch the merge could not delete as a step that failed after it, and leaves the card done', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    // main's git refuses to delete the card's branch, so finish keeps it and says so on stderr
    $hooks = $this->code->root().'/'.trim((string) (new Process(['git', 'rev-parse', '--git-path', 'hooks'], $this->code->root()))->mustRun()->getOutput());
    @mkdir($hooks, 0775, true);
    file_put_contents($hooks.'/reference-transaction', "#!/bin/sh\n[ \"\$1\" = prepared ] || exit 0\n"
        ."while read -r old new ref; do case \"\$new \$ref\" in 0000000000000000000000000000000000000000\\ refs/heads/card/*) exit 1 ;; esac; done\n");
    chmod($hooks.'/reference-transaction', 0755);

    runPass($this->code, $this->claude);
    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->toContain("merged {$id} into main")
        ->toMatch("/attention:\n  {$id} merged, then: branch card\\/\\S+ kept: git branch -D refused\n/")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'done', 'blocked' => null]);
});

it('judges a merge lease another machine holds by what this pass saw of it, also while a merge runs here', function () {
    $id = $this->code->started('Tag notes');
    $this->code->commit($id, 'notes.php', "<?php\n");
    $this->code->approve($id);
    $lease = function (string $beat) use ($id) {
        $board = $this->code->root().'/docs/kanban/kanban.json';
        $kanban = json_decode((string) file_get_contents($board), true);
        $kanban['merge'] = ['id' => '9f2c41d07a8b3e65', 'card' => $id, 'by' => 'ana@host-a', 'who' => 'Ana', 'since' => '2026-10-09T08:00:00.000+00:00', 'beat' => $beat];
        Json::write($board, Json::encode($kanban, 'kanban'));
        $this->code->sandbox->boardGit('commit', '-q', '-am', 'merge lease (test)');
    };
    $lease('2026-10-09T08:04:00.000+00:00');
    $this->code->seen(500);
    expect(runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']))->toContain('no beat for 8 min');
    $state = $this->code->root().'/.git/laravel-house/run.json';
    file_put_contents($state, json_encode(array_diff_key(json_decode((string) file_get_contents($state), true), ['upstream' => 0])));
    $run = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run', '--until-attention', '--timeout=10'], $this->code->root(), $this->code->env([
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]), null, 60);
    $run->start();
    // its first pass has seen the lease stand still (watch() writes `upstream` last), then a merge runs here and the holder beats
    for ($until = microtime(true) + 20; ! array_key_exists('upstream', (array) json_decode((string) @file_get_contents($state), true)) && $run->isRunning() && microtime(true) < $until;) {
        usleep(50_000);
    }
    $lock = fopen($this->code->root().'/.git/laravel-house/merge.run.lock', 'c');
    flock($lock, LOCK_EX);
    $lease('2026-10-09T08:06:00.000+00:00');
    file_put_contents($state, json_encode(array_diff_key(json_decode((string) file_get_contents($state), true), ['upstream' => 0])));

    try {
        $run->wait();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect($run->getOutput())->not->toContain('no beat for')->toContain('nothing needs you')
        ->and(json_decode((string) file_get_contents($state), true))->toHaveKey('upstream');
});

it('raises a done card\'s leftovers it cannot tidy once, as leftovers, across runs', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    file_put_contents($this->code->worktree($id).'/feature.txt', "edited after the approval\n");
    runPass($this->code, $this->claude);

    $merged = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $runs = array_map(fn () => runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']), range(1, 4));

    $leftovers = array_filter($runs, fn (string $out) => str_contains($out, "  {$id} done; its leftovers here: removing the clone"));
    expect($merged)->toContain("  {$id} merged, then: removing the clone")
        ->and(implode('', $runs))->not->toContain('merged, then')
        ->and($leftovers)->toHaveCount(1)
        ->and(is_file($this->code->worktree($id).'/feature.txt'))->toBeTrue()
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'done', 'blocked' => null]);
});
it('reads the agent models at each launch, so a change reaches the next agent of a run already going, and names them', function () {
    runAgent($this->claude, 'worker', 'true');
    $first = $this->code->sandbox->readyCard('Add login page');
    $second = $this->code->sandbox->readyCard('Add logout page');
    $run = $this->code->sandbox->start(['run', '--once'], $this->code->env([
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $this->claude, 'FAKE_DOCKER_DELAY' => '1',
    ]));
    $deadline = microtime(true) + 30;
    while (runLaunches($this->claude) === [] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $this->code->configure(['gates' => ['report' => []], 'agents' => ['worker' => ['model' => 'haiku', 'effort' => 'low']]]);
    $run->wait();
    while (count(runLaunches($this->claude)) < 2 && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect(array_map(fn (array $a) => array_slice($a, 3, 4), runLaunches($this->claude)))->toBe([['--model', 'sonnet', '--effort', 'high'], ['--model', 'haiku', '--effort', 'low']])
        ->and($run->getOutput())->toMatch("/ {$first} started; worker [0-9a-f]{8} launched \\(sonnet, high\\)\n/")
        ->toMatch("/ {$second} started; worker [0-9a-f]{8} launched \\(haiku, low\\)\n/");
});

it('blocks a card its evaluator rejects twice on the same criteria instead of a third round, and resumes it once unblocked', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" reject --check=1:fail:"feature.txt lacks the index: add it after the header"
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    foreach (range(1, 4) as $n) {
        runPass($this->code, $this->claude);
    }
    $workers = fn () => count(array_filter(runLaunches($this->claude), fn (array $a) => $a[2] === 'kanban-worker'));
    expect($workers())->toBe(2);

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    $why = 'kanban run: rejected 2× on criterion 1: feature.txt lacks the index: add it after the header';
    expect($this->code->sandbox->read($id))->toMatchArray(['stage' => 'doing', 'blocked' => $why])
        ->and($out)->toContain("attention:\n  {$id} blocked: {$why}\n")
        ->and(runPass($this->code, $this->claude))->not->toContain("{$id} worker")
        ->and($workers())->toBe(2);

    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    runPass($this->code, $this->claude);
    expect($workers())->toBe(3)
        ->and($this->code->sandbox->read($id)['blocked'])->toBeNull();
});

it('blocks a card on a reject loop in a later pass when the block could not be written at first', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" reject --check=1:fail:"feature.txt lacks the index"
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    foreach (range(1, 4) as $n) {
        runPass($this->code, $this->claude);
    }
    $board = dirname(glob($this->code->root()."/docs/kanban/*/{$id}.json")[0]);
    chmod($board, 0555);
    try {
        runPass($this->code, $this->claude);
    } finally {
        chmod($board, 0775);
    }
    expect($this->code->sandbox->read($id)['blocked'])->toBeNull();

    runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id)['blocked'])->toBe('kanban run: rejected 2× on criterion 1: feature.txt lacks the index');
});

it('tells the main session about a failure an agent found on main, once for each command, and holds nothing', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo x > feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done --discovered="main: php artisan test — NotesTest fatals"
        SH);
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" approve --check=1:pass:"feature.txt holds it" --discovered="main: php artisan test — the same fatal" --discovered="main: npm run lint — eslint fails"
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    $sha = substr(trim($this->code->sandbox->git('rev-parse', 'main')), 0, 7);
    $cards = count(glob($this->code->root().'/docs/kanban/work/*.json'));

    $out = '';
    for ($pass = 0; $pass < 6 && $this->code->sandbox->read($id)['stage'] !== 'done'; $pass++) {
        $out .= runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    }
    $out .= runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    $test = "  main is red: `php artisan test` fails on main at {$sha}, reported by the worker of {$id}: NotesTest fatals; fix it on main and `vendor/bin/kanban publish`\n";
    $lint = "  main is red: `npm run lint` fails on main at {$sha}, reported by the evaluator of {$id}: eslint fails; fix it on main and `vendor/bin/kanban publish`\n";
    expect($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and(count(glob($this->code->root().'/docs/kanban/work/*.json')))->toBe($cards)
        ->and($out)->toContain("attention:\n{$test}")
        ->and(substr_count($out, $test))->toBe(1)
        ->and(substr_count($out, $lint))->toBe(1)
        ->and(substr_count((string) file_get_contents($this->code->root().'/.git/laravel-house/run.log'), 'main is red'))->toBe(2)
        ->and(array_column(array_filter($this->code->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'main_red'), 'command'))->toBe(['php artisan test', 'npm run lint']);
});

it('raises a card promote --auto cannot write once, and promotes the others', function () {
    $s = $this->code->sandbox;
    $broken = $s->card('Broken', ['--body=x', '--accept=y', '--priority=high', '--label=area:a']);
    $card = $s->read($broken);
    $card['bogus'] = true;
    file_put_contents($s->root."/docs/kanban/work/{$broken}.json", json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $other = $s->card('Other', ['--body=x', '--accept=y', '--priority=low', '--label=area:b']);
    runAgent($this->claude, 'planner', 'exit 0');
    $log = fn () => (string) @file_get_contents($this->code->root().'/.git/laravel-house/run.log');

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->toContain("attention:\n")->toContain("  promote --auto: skipped {$broken}: invalid: ")
        ->and(substr_count($log(), "promote --auto: skipped {$broken}"))->toBe(1)
        ->and($s->read($other)['stage'])->toBe('planning');
});

it('counts one reject applied twice as one reject', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" reject --check=1:fail:"feature.txt lacks the index"
        SH);
    $s = $this->code->sandbox;
    $id = $s->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $this->code->ok(['apply', '--all']);
    $card = $s->read($id);
    $verdict = array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'verdict'));
    expect($card['stage'])->toBe('doing')->and($verdict)->toHaveCount(1);
    $card['log'][] = ['id' => 'ZZZZZZZZ'] + $verdict[0];
    file_put_contents(glob($s->root."/docs/kanban/*/{$id}.json")[0], json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    runPass($this->code, $this->claude);

    expect($s->read($id)['blocked'])->toBeNull();
});

it('launches no merger for a merge whose result landed while the pass looked at the queue', function () {
    $id = $this->code->started('Tag notes');
    $this->code->commit($id, 'RED', "the suite fails\n");
    $this->code->approve($id);
    runPass($this->code, $this->claude);
    expect($this->code->mergeState()['phase'])->toBe('red');
    // a merger's Stop hook applies its result at the moment the pass asks main's config whether the queue can merge
    $config = $this->code->root().'/config/kanban.php';
    $merge = $this->code->root().'/.git/laravel-house/merge.json';
    file_put_contents($config, str_replace("<?php\n", "<?php\n\nif (in_array('cannotMerge', array_column(debug_backtrace(), 'function'), true)) {\n"
        .'    file_put_contents('.var_export($merge, true).', json_encode([\'phase\' => \'checks\', \'merger_runs\' => 0] + json_decode(file_get_contents('.var_export($merge, true)."), true)));\n}\n", (string) file_get_contents($config)));

    runPass($this->code, $this->claude);

    expect(array_map(fn (array $argv) => $argv[2], runLaunches($this->claude)))->not->toContain('kanban-merger')
        ->and($this->code->mergeState()['phase'])->toBe('checks');
});

it('names the merge left waiting here while the queue cannot merge, and how to end it', function () {
    $id = $this->code->started('Tag notes');
    $this->code->commit($id, 'RED', "the suite fails\n");
    $this->code->approve($id);
    runPass($this->code, $this->claude);
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => []]]);

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->toContain("attention:\n  finish.check names no suite: no card merges until it does (config/kanban.php); "
        ."the merge of {$id} waits here until then (`vendor/bin/kanban finish {$id} --abort` ends it)\n")
        ->and($this->code->mergeState()['phase'])->toBe('red');
});

it('raises the idle notice while only a follow of the remote\'s main runs here', function () {
    $runtime = $this->code->root().'/.git/laravel-house';
    @mkdir("{$runtime}/merge", 0775, true);
    file_put_contents("{$runtime}/merge/run.json", json_encode(['pid' => 0, 'card' => 'follow', 'started' => '2026-01-01T00:00:00Z']));
    $lock = fopen("{$runtime}/merge.run.lock", 'c');
    flock($lock, LOCK_EX);

    $out = $this->code->kanban(['run', '--until-attention', '--timeout=0'], [
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]);
    flock($lock, LOCK_UN);

    expect($out->getOutput())->toContain("attention:\n  idle: no agent runs and no card can start");
});
