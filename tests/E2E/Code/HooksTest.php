<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;
use PetarSpasic\Kanban\Tests\Support\Sandbox;
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
        ->and(glob($code->root().'/.git/laravel-kanban/spawns/*'))->toBe([]);
});

it('ignores a spawn record older than two minutes', function () {
    $code = $this->code;
    $id = $code->started('Late spawn');
    @mkdir($code->root().'/.git/laravel-kanban/spawns', 0775, true);
    file_put_contents($code->root()."/.git/laravel-kanban/spawns/{$id}.json", json_encode(['card' => $id, 'agent_type' => 'kanban-worker', 'at' => microtime(true) - 300]));

    expect($code->hook('worktree-create', ['name' => 'agent-a1a7e000000000000'])->getOutput())->toBe($code->root()."/.claude/worktrees/agent-a1a7e000000000000\n")
        ->and(glob($code->root().'/.git/laravel-kanban/spawns/*'))->toBe([]);
});

it('keeps a spawn record for the isolated agent that claims it, not for any other worktree request', function () {
    $code = $this->code;
    $id = $code->started('Only the agent');
    @mkdir($code->root().'/.git/laravel-kanban/spawns', 0775, true);
    $record = $code->root()."/.git/laravel-kanban/spawns/{$id}.json";
    file_put_contents($record, json_encode(['card' => $id, 'agent_type' => 'kanban-worker', 'at' => microtime(true)]));

    $other = $code->hook('worktree-create', ['name' => 'scratch-experiment']);

    expect($other->getOutput())->toBe($code->root()."/.claude/worktrees/scratch-experiment\n")
        ->and($record)->toBeFile()
        ->and($code->hook('worktree-create', ['name' => 'agent-a0123456789abcdef'])->getOutput())->toBe($code->worktree($id)."\n");
});

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
        touch($code->root()."/.claude/worktrees/{$name}", time() - 3 * 86400);
    }

    $start = $code->hook('session-start', []);

    expect($start->getExitCode())->toBe(0)
        ->and($code->root()."/.claude/worktrees/{$idle}")->not->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$dirty}")->toBeDirectory()
        ->and($code->root()."/.claude/worktrees/{$fresh}")->toBeDirectory()
        ->and(array_column($code->stacks(), 'worktree'))->not->toContain($code->root()."/.claude/worktrees/{$idle}")
        ->and($code->stacks())->toHaveCount(2);
});

it('redoes a dependency copy that was killed halfway', function () {
    $code = $this->code;
    $code->hook('worktree-create', ['name' => 'redo']);
    $wt = $code->root().'/.claude/worktrees/redo';
    (new Process(['rm', '-rf', $wt.'/node_modules']))->run();
    mkdir($wt.'/node_modules.copying/left-pad', 0775, true);
    file_put_contents($wt.'/node_modules.copying/stale.txt', "half\n");

    $run = $code->hook('worktree-create', ['name' => 'redo']);

    expect($run->getExitCode())->toBe(0)
        ->and(is_file($wt.'/node_modules/left-pad/index.js'))->toBeTrue()
        ->and($wt.'/node_modules.copying')->not->toBeDirectory();
});
