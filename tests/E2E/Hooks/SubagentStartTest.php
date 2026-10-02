<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
});

it('records a kanban worker and gives it the board rules and the cards in doing', function () {
    $start = $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $context = json_decode($start->getOutput(), true)['hookSpecificOutput'];

    expect($start->getExitCode())->toBe(0)
        ->and($context['hookEventName'])->toBe('SubagentStart')
        ->and($context['additionalContext'])
        ->toStartWith("Kanban board: {$this->p->main}/docs/kanban (branch kanban).")
        ->toContain('change it only via vendor/bin/kanban, never edit it')
        ->toContain('Git: add/commit only in your own card worktree')
        ->toContain("Doing: {$this->id} .claude/worktrees/".basename($this->wt).'.')
        ->and(mb_strlen(str_replace($this->p->main, '', $context['additionalContext'])))->toBeLessThan(450)
        ->and($this->p->agent('a4d2c0ffee'))->toMatchArray(['agent_id' => 'a4d2c0ffee', 'agent_type' => 'kanban-worker', 'card' => null, 'stopped_at' => null, 'stop_blocks' => 0])
        ->and($this->p->agent('a4d2c0ffee')['started_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00$/');
});

it('keeps the guard binding when a bound worker is resumed', function () {
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
    $bound = $this->p->agent('a4d2c0ffee');
    expect($bound['card'])->toBe($this->id);
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));

    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['cwd' => $this->wt]));

    expect($this->p->agent('a4d2c0ffee'))->toMatchArray([
        'card' => $this->id, 'worktree' => '.claude/worktrees/'.basename($this->wt), 'bound_at' => $bound['bound_at'], 'stopped_at' => null, 'stop_blocks' => 1,
    ]);
});

it('says nothing to Explore and Plan, and does not record other agents', function () {
    foreach (['Explore', 'Plan'] as $type) {
        $start = $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'x-'.$type, 'type' => $type]));
        expect($start->getExitCode())->toBe(0)->and($start->getOutput())->toBe('');
    }
    $general = $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'gp1', 'type' => 'general-purpose']));

    expect(json_decode($general->getOutput(), true)['hookSpecificOutput']['additionalContext'])->toContain('Kanban board:')
        ->and($this->p->agent('gp1'))->toBeNull()
        ->and($this->p->agent('x-Explore'))->toBeNull();
});
