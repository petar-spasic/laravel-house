<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeSettings;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $this->code->configure(['gates' => ['report' => []]]);
    $this->claude = Sandbox::tmp();
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo "$RANDOM" >> feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" approve --check=1:pass:"feature.txt holds it"
        SH);
});

/** What the fake claude does for a kanban agent of $type. */
function runAgent(string $claude, string $type, string $script): void
{
    file_put_contents("{$claude}/kanban-{$type}.sh", $script);
}

/** One `run --once` pass, then waits until the agents it launched have ended. */
function runPass(CodeSandbox $code, string $claude, array $args = ['--once'], array $env = []): string
{
    $process = $code->kanban(['run', ...$args], [
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'),
        'FAKE_CLAUDE_DIR' => $claude,
    ] + $env);
    $deadline = microtime(true) + 60;
    do {
        $live = array_filter(glob($code->root().'/.git/laravel-house/runs/*.pid') ?: [],
            fn (string $f) => posix_kill((int) (json_decode((string) file_get_contents($f), true)['pid'] ?? 0), 0));
        $live === [] || usleep(100_000);
    } while ($live !== [] && microtime(true) < $deadline);

    return $process->getOutput().$process->getErrorOutput();
}

/** @return list<list<string>> the fake claude's argv, one per launch */
function runLaunches(string $claude): array
{
    $log = $claude.'/calls.log';

    return is_file($log) ? array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($log))))) : [];
}

it('takes a ready card to done: a headless worker, a headless evaluator, then the merge', function () {
    $id = $this->code->sandbox->readyCard('Add login page');

    $first = runPass($this->code, $this->claude);
    $wt = realpath($this->code->worktree($id));
    expect($first)->toContain('driving the board as run:')->toMatch("/ {$id} started; worker [0-9a-f]{8} launched\n/")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');

    $second = runPass($this->code, $this->claude);
    expect($second)->toContain("{$id} worker")->toContain('ended')->toContain("{$id} evaluator")
        ->and($this->code->sandbox->read($id)['work']['approved'])->not->toBeNull();

    $third = runPass($this->code, $this->claude);
    expect($third)->toContain("merged {$id} into main")
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

    foreach (range(1, 5) as $n) {
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
        ->and($back)->toMatch("/ {$id} worker [0-9a-f]{8} resumed, main not merged: uncommitted changes in its clone\n/")
        ->and(is_file($wt.'/fix.txt'))->toBeFalse()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');

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

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched\n/")
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

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched\n/")
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

    expect($out)->toMatch("/ {$id} started; worker [0-9a-f]{8} launched\n/");
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

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched\n/")->not->toContain("{$id} blocked")
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

    expect($out)->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched\n/")
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
    $finished = runPass($this->code, $this->claude);

    expect($evaluated)->not->toContain('back to doing')->toContain("{$id} evaluator")
        ->and($finished)->toContain("merged {$id} into main")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('blocks and reports a review card that refresh refuses, instead of waiting on it', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    $this->code->gitIn($this->code->worktree($id), 'checkout', '-q', '--detach');

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->toContain("attention:\n")->toContain("{$id} blocked: kanban run: ")->toContain('not on its branch');
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

    expect($out)->toMatch("/ {$id} worker [0-9a-f]{8} resumed\n/")
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
    expect($freed)->toContain("freed: {$project} down")->toMatch("/ {$left} started; worker [0-9a-f]{8} launched\n/")
        ->and($this->code->calls())->toContain("compose -p {$project} down -v --remove-orphans");
});

it('finishes a start whose clone was made but whose stack never came up, instead of sending a worker into no container', function () {
    $id = $this->code->started('Add login page');
    $file = glob($this->code->root().'/docs/kanban/*/'.$id.'.json')[0];
    $card = json_decode((string) file_get_contents($file), true);
    $card['work']['stack'] = null;
    Json::write($file, Json::encode($card, 'card'));
    $this->code->sandbox->boardGit('commit', '-q', '-am', "{$id} stack lost (test)");

    expect(runPass($this->code, $this->claude))->toMatch("/ {$id} start resumed; worker [0-9a-f]{8} launched\n/");
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

    expect($free)->toMatch("/ {$lost} start resumed; worker [0-9a-f]{8} launched\n/")->not->toContain("{$next} started")
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

it('starts an answered question card on its kept branch ahead of new cards, in a drain too', function () {
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

    expect($this->code->sandbox->ok(['answer', $id, '--note=One per team.']))->toContain("promoted {$id}: its parked branch card/");
    $out = runPass($this->code, $this->claude, ['--drain', '--once']);

    expect($out)->toMatch("/ {$id} started; worker [0-9a-f]{8} launched\n/")
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
        ->and(runPass($this->code, $this->claude))->toMatch("/ {$id} started; worker [0-9a-f]{8} launched\n/");
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

it('asks the owner about an approved change to files that steer the agents, and merges it once approved', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && mkdir -p .husky && echo '#!/bin/sh' > .husky/pre-commit && echo x > feature.txt && git add -A && git commit -qm "$CARD: hook" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    $id = $this->code->sandbox->readyCard('Add a commit hook');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    $asked = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $again = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($asked)->toContain("attention:\n  {$id} waits on the owner: question: merge with changes to files that steer the agents or git?\n")
        ->and($again)->not->toContain("{$id} waits on the owner")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'review', 'blocked' => 'question: merge with changes to files that steer the agents or git?']);

    expect(runPass($this->code, $this->claude, ['--drain', '--until-attention', '--timeout=0']))->toContain('drained: no card in flight');

    $this->code->sandbox->ok(['answer', $id, '1']);
    expect(runPass($this->code, $this->claude))->toContain("merged {$id} into main")
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');
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

it('blocks a card on the board when a command fails for it, and says so', function () {
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    file_put_contents($this->code->root().'/feature.txt', "local edit\n");

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($this->code->sandbox->read($id))->toMatchArray(['stage' => 'review'])
        ->and($this->code->sandbox->read($id)['blocked'])->toStartWith('kanban run: ')
        ->and($out)->toContain("attention:\n")->toContain("\n  {$id} blocked: kanban run: ");
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
