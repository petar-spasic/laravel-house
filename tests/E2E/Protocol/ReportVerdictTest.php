<?php

use PetarSpasic\Kanban\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    [$this->other, $this->otherWt] = $this->p->started('Other card');
});

it('stages a report only from the card\'s own worktree', function () {
    $main = $this->p->sandbox->kanban(['report', $this->id, '--status=review', '--summary=Done']);
    expect($main->getExitCode())->toBe(3)
        ->and($main->getErrorOutput())->toBe("report runs from {$this->id}'s worktree: cd {$this->wt}\n");

    $wrong = $this->p->in($this->otherWt, ['report', $this->id, '--status=review', '--summary=Done']);
    expect($wrong->getExitCode())->toBe(3);

    mkdir($this->wt.'/app');
    $ok = $this->p->in($this->wt.'/app', ['report', $this->id, '--status=review', '--tick=2', '--tick=1', '--summary=Done', '--verified=pest → 3 passed', '--note=fyi']);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toContain('warning: No commits beyond work.base');

    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.report.json")), true);
    expect(array_keys($staged))->toBe(['card', 'status', 'ticks', 'summary', 'verified', 'discovered', 'reason', 'note', 'head', 'worktree', 'session', 'staged_at', 'hash'])
        ->and($staged)->toMatchArray(['card' => $this->id, 'status' => 'review', 'ticks' => [1, 2], 'summary' => 'Done', 'verified' => ['pest → 3 passed'],
            'discovered' => [], 'reason' => null, 'note' => 'fyi', 'worktree' => '.claude/worktrees/'.strtolower($this->id), 'session' => null])
        ->and($staged['head'])->toBe(trim($this->p->git($this->wt, 'rev-parse', 'HEAD')))
        ->and($staged['hash'])->toMatch('/^[0-9a-f]{16}$/');
});

it('validates a report against the card', function (array $args, string $error) {
    $process = $this->p->in($this->wt, ['report', $this->id, ...$args]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($error)
        ->and(is_file($this->p->runtime("staged/{$this->id}.report.json")))->toBeFalse();
})->with([
    'unknown criterion' => [['--status=review', '--summary=x', '--tick=3'], 'has no criterion 3 (criteria: 1, 2)'],
    'bad status' => [['--status=done', '--summary=x'], "--status must be review or blocked, not 'done'"],
    'review without summary' => [['--status=review'], 'a review report needs --summary'],
    'blocked without reason' => [['--status=blocked'], 'a blocked report needs --reason'],
    'bad discovered type' => [['--status=review', '--summary=x', '--discovered=idea: Something'], 'type must be one of feature, bug, chore, spike'],
]);

it('refuses a report for a card that is not in doing', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=?'])->mustRun();
    $this->p->sandbox->ok(['stop', $this->other, '--to=ready', '--force'], ['KANBAN_SESSION' => 's']);
    $ready = $this->p->sandbox->kanban(['report', $this->other, '--status=review', '--summary=x'], cwd: $this->p->main);

    expect($ready->getExitCode())->toBe(3);
});

it('stages a verdict only with every criterion covered and a consistent decision', function () {
    $this->p->commit($this->wt, 'app.php');
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--summary=Done'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt]));

    $early = $this->p->in($this->otherWt, ['verdict', $this->other, 'approve', '--check=1:pass:ok', '--check=2:pass:ok']);
    expect($early->getExitCode())->toBe(3)->and($early->getErrorOutput())->toContain('is doing, not review');

    $cases = [
        [['approve', '--check=1:pass:ok'], 'every criterion needs a --check; missing: 2'],
        [['approve', '--check=1:pass:ok', '--check=2:fail:broken'], 'approve needs every check passing and no --issue (failing: 2)'],
        [['approve', '--check=1:pass:ok', '--check=2:pass:ok', '--issue=scope creep'], 'approve needs every check passing and no --issue'],
        [['reject', '--check=1:pass:ok', '--check=2:pass:ok'], 'reject needs a failing --check or an --issue'],
        [['reject', '--check=1:maybe:ok'], "--check '1:maybe:ok': use N:pass|fail:\"evidence\""],
        [['reject', '--check=7:fail:x'], '--check 7: '],
        [['maybe'], "the verdict is approve or reject, not 'maybe'"],
        [['approve', '--check=1:pass:ok', '--check=2:pass:ok', '--discovered=idea: Something'], 'type must be one of feature, bug, chore, spike'],
    ];
    foreach ($cases as [$args, $error]) {
        $process = $this->p->in($this->wt, ['verdict', $this->id, ...$args]);
        expect($process->getExitCode())->toBe(2)->and($process->getErrorOutput())->toContain($error);
    }

    $ok = $this->p->in($this->wt, ['verdict', $this->id, 'reject', '--check=1:pass:curl shows it', '--check=2:fail:no test',
        '--discovered=bug: Login fails on main — /login answers 500 without this change']);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toMatch("/^staged verdict for {$this->id}: reject at [0-9a-f]{7}, failing 2, 1 discovered\napplied when you stop\n$/");
    $staged = json_decode(file_get_contents($this->p->runtime("staged/{$this->id}.verdict.json")), true);
    expect($staged)->toMatchArray(['decision' => 'reject', 'checks' => ['1' => ['result' => 'pass', 'evidence' => 'curl shows it'], '2' => ['result' => 'fail', 'evidence' => 'no test']], 'issues' => [],
        'discovered' => [['type' => 'bug', 'title' => 'Login fails on main', 'body' => '/login answers 500 without this change']]])
        ->and($staged['base'])->toBe(trim($this->p->git($this->wt, 'merge-base', 'HEAD', 'main')));
});
