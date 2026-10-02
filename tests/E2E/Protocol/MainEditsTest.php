<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
    $this->main = ['KANBAN_SESSION' => 's1'];
});

it('rewords a criterion of a card in doing with --reason: logged as a note at the worktree head, unticked, the card kept', function () {
    $p = $this->p;
    $head = $p->commit($this->wt, 'app.php');
    $p->sandbox->ok(['set', $this->id, 'tick=1'], $this->main);

    $out = $p->sandbox->ok(['set', $this->id, 'accept[1]=It renders on mobile', '--reason=the owner asked for mobile'], $this->main);
    $card = $p->card($this->id);
    $note = array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'note'))[0];

    expect($out)->toContain("{$this->id} updated")
        ->and($card['stage'])->toBe('doing')
        ->and($card['acceptance'][0])->toBe(['id' => 1, 'text' => 'It renders on mobile', 'done' => false])
        ->and($note)->toMatchArray(['by' => 'main', 'text' => 'criterion 1 reworded: It renders → It renders on mobile: the owner asked for mobile', 'head' => $head])
        ->and($p->in($this->wt, ['context'])->getOutput())->toContain(' @'.substr($head, 0, 7).': criterion 1 reworded: It renders → It renders on mobile: the owner asked for mobile');
});

it('refuses a reword without --reason, and anything beyond the text of existing criteria with it', function () {
    $p = $this->p;
    $before = $p->card($this->id);

    $bare = $p->sandbox->kanban(['set', $this->id, 'accept[1]=Other'], $this->main);
    $added = $p->sandbox->kanban(['set', $this->id, 'accept+=More', '--reason=why'], $this->main);
    $title = $p->sandbox->kanban(['set', $this->id, 'accept[1]=Other', 'title=Renamed', '--reason=why'], $this->main);
    $nothing = $p->sandbox->kanban(['set', $this->id, 'note=Just a note', '--reason=why'], $this->main);

    expect($bare->getExitCode())->toBe(3)->and($bare->getErrorOutput())->toContain('a criterion reworded with --reason can')
        ->and($added->getExitCode())->toBe(3)
        ->and($title->getExitCode())->toBe(3)->and($title->getErrorOutput())->toContain('title cannot change')
        ->and($nothing->getExitCode())->toBe(2)->and($nothing->getErrorOutput())->toContain('--reason goes with a reworded criterion')
        ->and($p->card($this->id))->toBe($before);
});

it('sends a card in review back to doing when a criterion is reworded, dropping its approval, in one commit', function () {
    $p = $this->p;
    $p->commit($this->wt, 'app.php');
    $p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    $p->hook('subagent-start', $p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $p->enter($this->wt, 'e1', 'kanban-evaluator');
    $p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e1', 'type' => 'kanban-evaluator']));
    expect($p->card($this->id)['work']['approved'])->not->toBeNull();
    $commits = count($p->sandbox->boardLog());

    $p->sandbox->ok(['set', $this->id, 'accept[2]=It is tested in a browser', '--reason=unit tests are not enough'], $this->main);
    $card = $p->card($this->id);

    expect($card['stage'])->toBe('doing')
        ->and($card['work']['approved'])->toBeNull()
        ->and(array_column($card['acceptance'], 'done'))->toBe([true, false])
        ->and(array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'stage' && $e['from'] === 'review')))->toHaveCount(1)
        ->and(array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'stage' && $e['from'] === 'review'))[0])->toMatchArray(['from' => 'review', 'to' => 'doing', 'reason' => 'criteria 2 reworded'])
        ->and(count($p->sandbox->boardLog()))->toBe($commits + 1);
});

it('stamps the worktree head on what main ticks, and context names main beside the tick', function () {
    $p = $this->p;
    $head = $p->commit($this->wt, 'app.php');

    $p->sandbox->ok(['set', $this->id, 'tick=2'], $this->main);
    $card = $p->card($this->id);
    $context = $p->in($this->wt, ['context'])->getOutput();

    expect(array_values(array_filter($card['log'], fn (array $e) => $e['event'] === 'tick'))[0])->toMatchArray(['by' => 'main', 'ids' => [2], 'done' => true, 'head' => $head])
        ->and($context)->toContain('  [x] 2. It is tested (main @'.substr($head, 0, 7).')')
        ->and($context)->toContain('  [ ] 1. It renders'."\n");

    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--tick=2'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    expect($p->in($this->wt, ['context'])->getOutput())->toContain("  [x] 2. It is tested\n");
});
