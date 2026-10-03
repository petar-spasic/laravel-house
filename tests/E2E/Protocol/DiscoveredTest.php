<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Conditional clauses');
});

/** @return list<array<string, mixed>> the cards labelled `discovered` */
function discoveredCards(ProtocolSandbox $p): array
{
    return array_values(array_filter(array_map(fn (string $f) => json_decode((string) file_get_contents($f), true), glob($p->main.'/docs/kanban/*/*.json') ?: []),
        fn (array $c) => in_array('discovered', $c['labels'] ?? [], true)));
}

it('files a discovered item once: a title an open card, an earlier find of the card or the same report already has is not filed again', function () {
    $p = $this->p;
    $open = $p->sandbox->card('Fix the PDF footer');

    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=bug: Totals round wrong — on invoices',
        '--discovered=bug: totals round  WRONG!', '--discovered=fix the pdf footer'])->mustRun();
    $first = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Still waiting', '--discovered=Totals round wrong.'])->mustRun();
    $second = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $filed = discoveredCards($p);
    $reports = array_values(array_filter($p->card($this->id)['log'], fn (array $e) => $e['event'] === 'report'));
    expect($filed)->toHaveCount(1)
        ->and($filed[0]['title'])->toBe('Totals round wrong')
        ->and($reports[0])->toMatchArray(['discovered' => [$filed[0]['id']], 'known' => [$open]])
        ->and($reports[1])->toMatchArray(['known' => [$filed[0]['id']]])->not->toHaveKey('discovered')
        ->and($first->getErrorOutput())->toContain("discovered {$filed[0]['id']}, already on the board: {$open}")
        ->and($second->getErrorOutput())->toContain("already on the board: {$filed[0]['id']}")
        ->and($p->in($this->wt, ['context'])->getOutput())->toContain("discovered earlier (on the board; never file them again): {$filed[0]['id']} Totals round wrong (backlog), {$open} Fix the PDF footer (backlog)\n");
});

it('files what an approval found when the card was finished on an earlier approval, without a stop block', function () {
    $p = $this->p;
    $p->commit($this->wt, 'app.php');
    $p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    foreach (['e1', 'e2'] as $agent) {
        $p->hook('subagent-start', $p->payload('subagent-start', ['agent' => $agent, 'type' => 'kanban-evaluator']));
    }
    $p->enter($this->wt, 'e1', 'kanban-evaluator');
    $p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e1', 'type' => 'kanban-evaluator']))->mustRun();
    $p->enter($this->wt, 'e2', 'kanban-evaluator');
    $p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok', '--discovered=chore: Cache the clause list'])->mustRun();
    $p->sandbox->ok(['finish', $this->id], ['KANBAN_SESSION' => 's1']);

    $stop = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e2', 'type' => 'kanban-evaluator']));

    $filed = discoveredCards($p);
    $card = $p->card($this->id);
    expect($stop->getExitCode())->toBe(0)
        ->and($stop->getOutput())->toBe('')
        ->and($stop->getErrorOutput())->toContain("{$this->id}: verdict moot: the card is done, discovered {$filed[0]['id']}")
        ->and(array_column($filed, 'title'))->toBe(['Cache the clause list'])
        ->and($card['stage'])->toBe('done')
        ->and(end($card['log']))->toMatchArray(['event' => 'verdict_moot', 'decision' => 'approve', 'discovered' => [$filed[0]['id']]]);
});
