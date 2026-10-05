<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
});

it('returns once the stop after a hand-back has applied the report', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1', '--summary=Done'])->mustRun();
    $wait = $this->p->sandbox->start(['wait', $this->id, '--timeout=20']);
    usleep(600_000);
    expect($wait->isRunning())->toBeTrue();

    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    $wait->wait();

    expect($wait->getExitCode())->toBe(0)
        ->and($wait->getOutput())->toStartWith("{$this->id} review ")->toEndWith(" — agent stopped\n");
});

it('says when the stop was refused, and waits again once the agent has worked on', function () {
    $wait = $this->p->sandbox->start(['wait', $this->id, '--timeout=20']);
    usleep(300_000);
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    $wait->wait();

    expect($wait->getExitCode())->toBe(0)
        ->and($wait->getOutput())->toContain("{$this->id} doing ")->toContain('stop refused, the agent works on: No report staged');

    touch($this->p->runtime('agents/a4d2c0ffee.json'), time() + 2);
    $again = $this->p->sandbox->kanban(['wait', $this->id, '--timeout=1']);
    expect($again->getExitCode())->toBe(75)
        ->and($again->getOutput())->toBe("still running after 1 s: {$this->id}; run `wait` again\n");
});

it('waits for any live agent without ids, and says when there is none', function () {
    $wait = $this->p->sandbox->start(['wait', '--timeout=20']);
    usleep(300_000);
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Needs a decision'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));
    $wait->wait();

    expect($wait->getOutput())->toContain("{$this->id} doing ")->toContain('[blocked: Needs a decision] — agent stopped')
        ->and($this->p->sandbox->ok(['wait']))->toBe("no live agents\n");
});

it('waits without ids only for cards in doing or review, never a leftover record of a card that left them', function () {
    $gone = $this->p->sandbox->card('Merged earlier');
    $agents = dirname($this->p->runtime('agents/a4d2c0ffee.json'));
    file_put_contents("{$agents}/leftover-live.json", json_encode(['agent_type' => 'kanban-evaluator', 'card' => $gone, 'stopped_at' => null]));
    file_put_contents("{$agents}/leftover-stopped.json", json_encode(['agent_type' => 'kanban-worker', 'card' => $gone, 'stopped_at' => '2026-01-01T00:00:00Z']));
    touch("{$agents}/leftover-live.json", time() - 5);

    $wait = $this->p->sandbox->kanban(['wait', '--timeout=1']);

    expect($wait->getExitCode())->toBe(75)
        ->and($wait->getOutput())->toBe("still running after 1 s: {$this->id}; run `wait` again\n");
});

it('is the main session\'s', function () {
    expect($this->p->in($this->wt, ['wait', $this->id])->getErrorOutput())->toContain('wait runs from the main checkout');
});
