<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

it('creates a plain worktree with deps and a slot, no containers, and prints only its path last', function () {
    $code = $this->code;

    $run = $code->hook('worktree-create', ['name' => 'agent-A1b2']);

    $wt = $code->root().'/.claude/worktrees/agent-a1b2';
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe($wt."\n")
        ->and($run->getErrorOutput())->toContain('slot 1')
        ->and(trim($code->gitIn($wt, 'rev-parse', '--abbrev-ref', 'HEAD')))->toBe('worktree-agent-a1b2')
        ->and(is_file($wt.'/node_modules/left-pad/index.js'))->toBeTrue()
        ->and(file_get_contents($wt.'/.env'))->toContain('COMPOSE_PROJECT_NAME=acme-wt-agent-a1b2')
        ->and($code->stacks()[0])->toMatchArray(['worktree' => $wt, 'card' => null, 'branch' => 'worktree-agent-a1b2'])
        ->and(collect($code->calls())->filter(fn ($c) => str_starts_with($c, 'compose'))->all())->toBe([]);

    expect($code->hook('worktree-create', ['name' => 'agent-A1b2'])->getOutput())->toBe($wt."\n")
        ->and($code->stacks())->toHaveCount(1);
});

it('returns the worktree of a card in doing for its name', function () {
    $code = $this->code;
    $id = $code->started('Enter me');
    $calls = count($code->calls());

    $run = $code->hook('worktree-create', ['name' => strtolower($id)]);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toBe($code->worktree($id)."\n")
        ->and($run->getErrorOutput())->toContain("is card {$id}")
        ->and($code->stacks())->toHaveCount(1)
        ->and(count($code->calls()))->toBe($calls);
});

it('leaves card worktrees alone on remove and tears down the others', function () {
    $code = $this->code;
    $id = $code->started('Protected');
    $plain = trim($code->hook('worktree-create', ['name' => 'scratch'])->getOutput());
    $committed = trim($code->hook('worktree-create', ['name' => 'kept'])->getOutput());
    file_put_contents($committed.'/kept.txt', "x\n");
    $code->gitIn($committed, 'add', '-A');
    $code->gitIn($committed, 'commit', '-q', '-m', 'kept work');

    $card = $code->hook('worktree-remove', ['worktree_path' => $code->worktree($id)]);
    $scratch = $code->hook('worktree-remove', ['worktree_path' => $plain]);
    $kept = $code->hook('worktree-remove', ['worktree_path' => $committed]);

    expect($card->getExitCode())->toBe(0)
        ->and($card->getErrorOutput())->toContain("is the worktree of {$id}")
        ->and(is_dir($code->worktree($id)))->toBeTrue()
        ->and($scratch->getExitCode())->toBe(0)
        ->and($scratch->getErrorOutput())->toContain('deleted branch worktree-scratch')
        ->and(is_dir($plain))->toBeFalse()
        ->and($kept->getErrorOutput())->toContain('kept branch worktree-kept (it has commits)')
        ->and(trim($code->sandbox->git('branch', '--list', 'worktree-*')))->toBe('worktree-kept')
        ->and(array_column($code->stacks(), 'card'))->toBe([$id])
        ->and($code->calls())->toContain('compose --project-directory '.$plain.' -f '.$plain.'/docker-compose.local.yml -p acme-wt-scratch down -v --remove-orphans --rmi local -t 5');
});

it('hands the card worktree to the isolated agent the main session spawned, once', function () {
    $code = $this->code;
    $id = $code->started('Isolated worker');
    $guard = new Process([PHP_BINARY, Sandbox::package().'/bin/kanban-guard'], $code->root());
    $guard->setInput(json_encode([
        'session_id' => 's', 'cwd' => $code->root(), 'hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent',
        'tool_input' => ['subagent_type' => 'kanban-worker', 'description' => $id, 'isolation' => 'worktree',
            'prompt' => "Card {$id}. Worktree ".$code->worktree($id)],
    ]));
    $guard->mustRun();
    expect($guard->getOutput())->toBe('');

    $isolated = $code->hook('worktree-create', ['name' => 'agent-a0c0ffee123456789']);
    $next = $code->hook('worktree-create', ['name' => 'agent-0dd5']);

    expect($isolated->getExitCode())->toBe(0)
        ->and($isolated->getOutput())->toBe($code->worktree($id)."\n")
        ->and($isolated->getErrorOutput())->toContain("kanban-worker spawned for {$id}")
        ->and($next->getOutput())->toBe($code->root()."/.claude/worktrees/agent-0dd5\n")
        ->and(glob($code->root().'/.git/laravel-house/spawns/*'))->toBe([]);
});

it('ignores a spawn record older than two minutes', function () {
    $code = $this->code;
    $id = $code->started('Late spawn');
    @mkdir($code->root().'/.git/laravel-house/spawns', 0775, true);
    file_put_contents($code->root()."/.git/laravel-house/spawns/{$id}.json", json_encode(['card' => $id, 'agent_type' => 'kanban-worker', 'at' => microtime(true) - 300]));

    expect($code->hook('worktree-create', ['name' => 'agent-a1a7e000000000000'])->getOutput())->toBe($code->root()."/.claude/worktrees/agent-a1a7e000000000000\n")
        ->and(glob($code->root().'/.git/laravel-house/spawns/*'))->toBe([]);
});

it('keeps a spawn record for the isolated agent that claims it, not for any other worktree request', function () {
    $code = $this->code;
    $id = $code->started('Only the agent');
    @mkdir($code->root().'/.git/laravel-house/spawns', 0775, true);
    $record = $code->root()."/.git/laravel-house/spawns/{$id}.json";
    file_put_contents($record, json_encode(['card' => $id, 'agent_type' => 'kanban-worker', 'at' => microtime(true)]));

    $other = $code->hook('worktree-create', ['name' => 'scratch-experiment']);

    expect($other->getOutput())->toBe($code->root()."/.claude/worktrees/scratch-experiment\n")
        ->and($record)->toBeFile()
        ->and($code->hook('worktree-create', ['name' => 'agent-a0123456789abcdef'])->getOutput())->toBe($code->worktree($id)."\n");
});

/** Waits (up to 15 s) for the reclaim SessionStart starts in the background. */
function waitUntil(callable $done): void
{
    for ($i = 0; $i < 150; $i++) {
        clearstatcache();
        if ($done()) {
            return;
        }
        usleep(100000);
    }
}

/** Makes an isolated-agent worktree look untouched for $days: the directory, its .git link and git's HEAD, index and reflog. */
function ageWorktree(CodeSandbox $code, string $name, int $days = 3): void
{
    $wt = $code->root()."/.claude/worktrees/{$name}";
    $admin = trim(preg_replace('/^gitdir:\s*/', '', (string) file_get_contents($wt.'/.git')));
    foreach ([$wt, $wt.'/.git', $admin.'/HEAD', $admin.'/index', $admin.'/logs/HEAD'] as $path) {
        if (file_exists($path)) {
            touch($path, time() - $days * 86400);
        }
    }
}

it('reclaims idle isolated-agent worktrees at session start and frees their slots', function () {
    $code = $this->code;
    $idle = 'agent-a0000000000000001';
    $dirty = 'agent-a0000000000000002';
    $fresh = 'agent-a0000000000000003';
    foreach ([$idle, $dirty, $fresh] as $name) {
        $code->hook('worktree-create', ['name' => $name]);
    }
    file_put_contents($code->root()."/.claude/worktrees/{$dirty}/notes.txt", "unsaved\n");
    foreach ([$idle, $dirty] as $name) {
        ageWorktree($code, $name);
    }

    $start = $code->hook('session-start', []);
    waitUntil(fn () => ! is_dir($code->root()."/.claude/worktrees/{$idle}"));

    expect($start->getExitCode())->toBe(0)
        ->and($code->root()."/.claude/worktrees/{$idle}")->not->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$dirty}")->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$fresh}")->toBeDirectory()
        ->and(array_column($code->stacks(), 'worktree'))->not->toContain($code->root()."/.claude/worktrees/{$idle}")
        ->and($code->stacks())->toHaveCount(2);
});

it('redoes a dependency copy that was killed halfway and never leaves it inside the worktree', function () {
    $code = $this->code;
    $code->hook('worktree-create', ['name' => 'redo']);
    $wt = $code->root().'/.claude/worktrees/redo';
    (new Process(['rm', '-rf', $wt.'/node_modules']))->run();
    $staging = $code->root().'/.claude/worktrees/.copying';
    mkdir($staging.'/redo-node_modules-1/left-pad', 0775, true);
    file_put_contents($staging.'/redo-node_modules-1/stale.txt', "half\n");

    $run = $code->hook('worktree-create', ['name' => 'redo']);

    expect($run->getExitCode())->toBe(0)
        ->and(is_file($wt.'/node_modules/left-pad/index.js'))->toBeTrue()
        ->and(is_file($wt.'/node_modules/stale.txt'))->toBeFalse()
        ->and(glob($wt.'/*.copying'))->toBe([])
        ->and(trim($code->gitIn($wt, 'status', '--porcelain')))->toBe('');
});

it('lets two hooks copy the same dependencies at once without clobbering each other', function () {
    $code = $this->code;
    $procs = [];
    foreach ([1, 2] as $_) {
        $payload = json_encode(['name' => 'twin-a', 'session_id' => 's', 'cwd' => $code->root(), 'hook_event_name' => 'worktree-create']);
        $procs[] = $code->sandbox->start(['hook', 'worktree-create'], $code->env(), $payload);
    }
    foreach ($procs as $proc) {
        $proc->wait();
    }

    $wt = $code->root().'/.claude/worktrees/twin-a';
    expect(is_file($wt.'/node_modules/left-pad/index.js'))->toBeTrue()
        ->and(glob($code->root().'/.claude/worktrees/.copying/*'))->toBe([]);
});

it('leaves an idle-looking agent worktree alone when it has recent git activity or a running stack', function () {
    $code = $this->code;
    $busy = 'agent-a00000000000000b1';
    $running = 'agent-a00000000000000b2';
    $gone = 'agent-a00000000000000b3';
    foreach ([$busy, $running, $gone] as $name) {
        $code->hook('worktree-create', ['name' => $name]);
    }
    $code->ok(['stack', 'up'], cwd: $code->root()."/.claude/worktrees/{$running}");
    foreach ([$busy, $running, $gone] as $name) {
        ageWorktree($code, $name);
    }
    $code->gitIn($code->root()."/.claude/worktrees/{$busy}", 'commit', '-q', '--allow-empty', '-m', 'just now');
    touch($code->root()."/.claude/worktrees/{$busy}", time() - 3 * 86400);

    $code->hook('session-start', []);
    waitUntil(fn () => ! is_dir($code->root()."/.claude/worktrees/{$gone}"));

    expect($code->root()."/.claude/worktrees/{$busy}")->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$running}")->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$gone}")->not->toBeDirectory();
});

it('does not treat a worktree whose git status fails as clean', function () {
    $code = $this->code;
    $name = 'agent-a00000000000000c1';
    $code->hook('worktree-create', ['name' => $name]);
    $wt = $code->root()."/.claude/worktrees/{$name}";
    ageWorktree($code, $name);
    file_put_contents($wt.'/.git', "gitdir: {$code->root()}/.git/worktrees/missing\n");
    touch($wt, time() - 3 * 86400);
    touch($wt.'/.git', time() - 3 * 86400);

    $code->hook('session-start', []);
    $code->ok(['sweep', '--reclaim']);

    expect($wt)->toBeDirectory();
});

it('reclaims at most ten idle agent worktrees per sweep', function () {
    $code = $this->code;
    foreach (range(1, 12) as $n) {
        $name = sprintf('agent-a%016x', $n);
        $code->hook('worktree-create', ['name' => $name]);
        ageWorktree($code, $name);
    }

    $code->ok(['sweep', '--reclaim']);

    expect(glob($code->root().'/.claude/worktrees/agent-a*', GLOB_ONLYDIR))->toHaveCount(2);
});

it('does not wait for the reclaim: the session start answers while a slow docker runs', function () {
    $code = $this->code;
    $name = 'agent-a00000000000000e1';
    $code->hook('worktree-create', ['name' => $name]);
    $code->ok(['stack', 'up'], cwd: $code->root()."/.claude/worktrees/{$name}");
    ageWorktree($code, $name);
    $calls = count($code->calls());

    $started = microtime(true);
    $start = $code->hook('session-start', [], ['FAKE_DOCKER_DELAY' => '2']);
    $took = microtime(true) - $started;

    expect($start->getExitCode())->toBe(0)
        ->and($start->getOutput())->toStartWith('Kanban ACME:')
        ->and($took)->toBeLessThan(1.8);
    waitUntil(fn () => count($code->calls()) > $calls);
    expect(count($code->calls()))->toBeGreaterThan($calls);
});

it('keeps an idle agent worktree with a stack when docker cannot say whether the stack runs', function () {
    $code = $this->code;
    $name = 'agent-a00000000000000f1';
    $code->hook('worktree-create', ['name' => $name]);
    ageWorktree($code, $name);

    $code->ok(['sweep', '--reclaim'], ['FAKE_DOCKER_FAIL' => 'ps']);

    expect($code->root()."/.claude/worktrees/{$name}")->toBeDirectory()
        ->and($code->stacks())->toHaveCount(1);

    $code->ok(['sweep', '--reclaim']);
    expect($code->root()."/.claude/worktrees/{$name}")->not->toBeDirectory();
});

it('starts no background process at session start when no agent worktree is idle', function () {
    $code = $this->code;
    $code->hook('worktree-create', ['name' => 'agent-a00000000000000f2']);
    $calls = count($code->calls());

    $code->hook('session-start', [], ['FAKE_DOCKER_DELAY' => '1']);
    sleep(2);

    expect(count($code->calls()))->toBe($calls)
        ->and(glob($code->root().'/.git/laravel-house/reclaim.lock'))->toBe([]);
});

it('does not start another background reclaim within the hour of the last one', function () {
    $code = $this->code;
    $name = 'agent-a00000000000000f3';
    $code->hook('worktree-create', ['name' => $name]);
    file_put_contents($code->root()."/.claude/worktrees/{$name}/unsaved.txt", "kept\n");
    ageWorktree($code, $name);
    $stamp = $code->root().'/.git/laravel-house/reclaim.last';

    $code->hook('session-start', []);
    waitUntil(fn () => is_file($stamp));
    expect($stamp)->toBeFile();
    sleep(2);
    touch($stamp, time() - 600);
    $ran = filemtime($stamp);

    $code->hook('session-start', []);
    sleep(2);
    clearstatcache();
    expect(filemtime($stamp))->toBe($ran);

    touch($stamp, time() - 7200);
    $code->hook('session-start', []);
    waitUntil(fn () => filemtime($stamp) > time() - 60);
    clearstatcache();
    expect(filemtime($stamp))->toBeGreaterThan(time() - 60);
});

it('reclaims every idle agent worktree when asked with stack gc, not only two', function () {
    $code = $this->code;
    foreach (['a1', 'a2', 'a3', 'a4'] as $suffix) {
        $name = "agent-a00000000000000{$suffix}";
        $code->hook('worktree-create', ['name' => $name]);
        ageWorktree($code, $name);
    }

    $out = $code->ok(['stack', 'gc']);

    expect($out)->toContain('reclaimed 4 idle agent worktree(s)')
        ->and(glob($code->root().'/.claude/worktrees/agent-a*', GLOB_ONLYDIR))->toBe([]);
});
