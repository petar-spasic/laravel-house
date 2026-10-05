<?php

use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
});

/** The record `kanban run` writes before it launches a headless card session. */
function bindHeadless(ProtocolSandbox $p, string $session, string $type, string $card, string $wt): void
{
    @mkdir($p->runtime('agents'), 0775, true);
    file_put_contents($p->runtime("agents/{$session}.json"), json_encode([
        'agent_id' => $session, 'agent_type' => $type, 'card' => $card, 'worktree' => $wt,
        'bound_at' => Clock::now(), 'started_at' => Clock::now(), 'stopped_at' => null, 'stop_blocks' => 0,
    ]));
}

function stopHeadless(ProtocolSandbox $p, string $session, ?string $type): array
{
    $process = $p->hook('stop', json_encode(array_filter([
        'session_id' => $session, 'transcript_path' => $p->main.'/.git/t.jsonl', 'cwd' => $p->main, 'hook_event_name' => 'Stop',
        'stop_hook_active' => false, 'agent_type' => $type, 'last_assistant_message' => 'Done.',
    ], fn ($v) => $v !== null)));

    return ['exit' => $process->getExitCode(), 'out' => $process->getOutput(), 'json' => json_decode($process->getOutput(), true)];
}

it('gates and applies the report of a headless worker by its session id, and leaves the main session alone', function () {
    $session = '5f0c9a2e-0000-4000-8000-0000000000aa';
    bindHeadless($this->p, $session, 'kanban-worker', $this->id, $this->wt);

    expect(stopHeadless($this->p, $session, 'kanban-worker')['json'])->toMatchArray(['decision' => 'block'])
        ->and(stopHeadless($this->p, 'the-main-session', null))->toMatchArray(['exit' => 0, 'out' => '']);

    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();

    expect(stopHeadless($this->p, $session, 'kanban-worker'))->toMatchArray(['exit' => 0, 'out' => ''])
        ->and($this->p->card($this->id)['stage'])->toBe('review')
        ->and($this->p->agent($session)['stopped_at'])->not->toBeNull();
});

it('blocks the card of an evaluator that stops three times without a verdict', function () {
    bindHeadless($this->p, 'worker-session', 'kanban-worker', $this->id, $this->wt);
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: clauses");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    stopHeadless($this->p, 'worker-session', 'kanban-worker');
    expect($this->p->card($this->id)['stage'])->toBe('review');
    $session = '5f0c9a2e-0000-4000-8000-0000000000bb';
    bindHeadless($this->p, $session, 'kanban-evaluator', $this->id, $this->wt);

    foreach ([1, 2, 3] as $n) {
        expect(stopHeadless($this->p, $session, 'kanban-evaluator')['json']['reason'])->toStartWith("No verdict staged for {$this->id}.");
    }
    stopHeadless($this->p, $session, 'kanban-evaluator');

    expect($this->p->card($this->id))->toMatchArray(['stage' => 'review', 'blocked' => 'evaluator stopped without verdict']);
});

it('gives a headless card session no brief and no KANBAN_SESSION', function () {
    $session = '5f0c9a2e-0000-4000-8000-0000000000cc';
    bindHeadless($this->p, $session, 'kanban-worker', $this->id, $this->wt);
    $envFile = $this->p->main.'/.git/claude-env';
    file_put_contents($envFile, '');

    $start = $this->p->hook('session-start', $this->p->payload('session-start', ['session' => $session], ['source' => 'resume']), ['CLAUDE_ENV_FILE' => $envFile]);

    expect($start->getExitCode())->toBe(0)
        ->and($start->getOutput())->toBe('')
        ->and(file_get_contents($envFile))->toBe('');
});
