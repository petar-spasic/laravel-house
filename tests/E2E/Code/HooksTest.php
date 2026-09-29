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

    $isolated = $code->hook('worktree-create', ['name' => 'agent-c0ffee12']);
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

    expect($code->hook('worktree-create', ['name' => 'agent-late'])->getOutput())->toBe($code->root()."/.claude/worktrees/agent-late\n")
        ->and(glob($code->root().'/.git/laravel-kanban/spawns/*'))->toBe([]);
});
