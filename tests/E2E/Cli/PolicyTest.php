<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('promotes through the ready policy and names every refusal', function () {
    $s = $this->sandbox;
    $bare = $s->card('Bare');
    $good = $s->card('Good', ['--body=Do it', '--accept=Works']);
    $blocked = $s->card('Blocked', ['--body=Do it', '--accept=Works']);
    $s->ok(['set', $blocked, 'blocked=owner']);
    $dropped = $s->card('Dropped dep');
    $s->ok(['move', $dropped, 'dropped', '--reason=no']);
    $proposal = $s->card('Undecided', board: 'project/decisions');
    $dependent = $s->card('Dependent', ['--body=Do it', '--accept=Works', "--depends={$dropped}", "--depends={$proposal}"]);
    $ready = $s->readyCard('Already ready');

    $run = $s->kanban(['promote', $bare, $good, $blocked, $dependent, $ready, $proposal]);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getOutput())->toBe(implode("\n", [
            "refused {$bare}: R3 empty body; R4 no acceptance criteria",
            "promoted {$good}",
            "refused {$blocked}: R6 blocked: owner",
            "refused {$dependent}: ".implode('; ', array_map(fn ($id) => $id === $dropped ? "R5 dependency {$id} is dropped" : "R5 decision {$id} is not decided", collect([$dropped, $proposal])->sort()->values()->all())),
            "refused {$ready}: R7 in ready, not backlog",
            "refused {$proposal}: R1 a decision is not work; R3 empty body; R4 no acceptance criteria; R7 in proposed, not backlog",
        ])."\n")
        ->and($s->read($good)['stage'])->toBe('ready');
});

it('refuses a dependency cycle', function () {
    $s = $this->sandbox;
    $a = $s->card('A', ['--body=x', '--accept=y']);
    $b = $s->card('B', ['--body=x', '--accept=y', "--depends={$a}"]);

    $cycle = $s->kanban(['set', $a, "depends_on=+{$b}"]);

    expect($cycle->getExitCode())->toBe(2)->and($cycle->getErrorOutput())->toContain('dependency cycle');
});

it('fills the ready buffer in pull order with --auto', function () {
    $s = $this->sandbox;
    $low = $s->card('Low', ['--body=x', '--accept=y', '--priority=low']);
    $high = $s->card('High', ['--body=x', '--accept=y', '--priority=high']);
    $s->card('Not ready yet');
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"ready_buffer": 12', '"ready_buffer": 1', file_get_contents($s->root.'/docs/kanban/kanban.json')));

    expect($s->ok(['promote', '--auto']))->toBe("promoted {$high}\nready 1/1\n")
        ->and($s->read($low)['stage'])->toBe('backlog');
});

it('orders next by the pull policy', function (Closure $setup, array|string $expected) {
    $ids = $setup($this->sandbox);

    $output = $this->sandbox->ok(['next', '--count=10']);

    $actual = is_string($expected) ? trim($output) : array_values(array_map(
        fn (string $line) => array_search(explode(' ', $line)[0], $ids, true),
        array_filter(explode("\n", $output)),
    ));
    expect($actual)->toBe($expected);
})->with([
    'priority first' => [fn (Sandbox $s) => [
        'a' => $s->readyCard('A'),
        'b' => $s->readyCard('B', ['--priority=high']),
    ], ['b', 'a']],
    'oldest in ready' => [fn (Sandbox $s) => [
        'a' => $s->readyCard('A'),
        'b' => $s->readyCard('B'),
    ], ['a', 'b']],
    'epic order before board order' => [function (Sandbox $s) {
        $s->ok(['board', 'platform/tooling', 'Tooling', '--order=1']);

        return ['t' => $s->readyCard('T', board: 'platform/tooling'), 'p' => $s->readyCard('P')];
    }, ['p', 't']],
    'board order within an epic' => [function (Sandbox $s) {
        $s->ok(['board', 'project/first', 'First', '--order=5']);

        return ['w' => $s->readyCard('W'), 'f' => $s->readyCard('F', board: 'project/first')];
    }, ['f', 'w']],
    'unsatisfied dependencies wait' => [function (Sandbox $s) {
        $dep = $s->card('Dep');

        return ['d' => $s->readyCard('D', ["--depends={$dep}"])];
    }, 'none: no startable ready cards'],
    'a decided decision satisfies a dependency' => [function (Sandbox $s) {
        $decision = $s->card('Chosen', ['--stage=decided'], 'project/decisions');

        return ['d' => $s->readyCard('D', ["--depends={$decision}"])];
    }, ['d']],
    'blocked cards wait' => [function (Sandbox $s) {
        $id = $s->readyCard('J');
        $s->ok(['set', $id, 'blocked=waiting']);

        return ['j' => $id];
    }, 'none: no startable ready cards'],
    'an area in flight serializes' => [function (Sandbox $s) {
        $busy = $s->readyCard('Busy', ['--label=area:pdf']);
        $s->ok(['claim', $busy], ['KANBAN_SESSION' => 's1']);

        return ['e' => $s->readyCard('E', ['--label=area:pdf', '--priority=high']), 'f' => $s->readyCard('F')];
    }, ['f']],
    'one area per pick' => [fn (Sandbox $s) => [
        'g' => $s->readyCard('G', ['--label=area:x', '--priority=high']),
        'h' => $s->readyCard('H', ['--label=area:x']),
        'i' => $s->readyCard('I'),
    ], ['g', 'i']],
    'board WIP full' => [function (Sandbox $s) {
        $s->ok(['claim', $s->readyCard('K')], ['KANBAN_SESSION' => 's1']);
        $s->ok(['board', 'project/work', '--wip-doing=1']);

        return ['l' => $s->readyCard('L')];
    }, 'none: board WIP limits reached'],
    'urgent expedites by one' => [function (Sandbox $s) {
        $s->ok(['claim', $s->readyCard('K')], ['KANBAN_SESSION' => 's1']);
        $s->ok(['board', 'project/work', '--wip-doing=1']);

        return ['l' => $s->readyCard('L'), 'm' => $s->readyCard('M', ['--priority=urgent']), 'n' => $s->readyCard('N', ['--priority=urgent'])];
    }, ['m']],
]);

it('stops starting when review is full', function () {
    $s = $this->sandbox;
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"review": 6', '"review": 1', file_get_contents($s->root.'/docs/kanban/kanban.json')));
    $id = $s->readyCard('In review');
    $s->ok(['claim', $id], ['KANBAN_SESSION' => 's1']);
    $card = $s->read($id);
    $card['stage'] = 'review';
    $card['work'] = ['branch' => 'card/x'];
    file_put_contents($s->root."/docs/kanban/project/work/{$id}.json", json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $s->readyCard('Waiting');

    expect($s->ok('next'))->toBe("none: review 1/1 (stop starting)\n")
        ->and(json_decode($s->ok(['next', '--json']), true))->toMatchArray(['cards' => [], 'reason' => 'review 1/1 (stop starting)']);
});

it('claims only within capacity unless urgent or forced', function () {
    $s = $this->sandbox;
    $s->ok(['board', 'project/work', '--wip-doing=1']);
    $first = $s->readyCard('First');
    $second = $s->readyCard('Second');
    $main = ['KANBAN_SESSION' => 's1'];

    expect($s->ok(['claim', $first], $main))->toStartWith("claimed {$first} by ");
    $refused = $s->kanban(['claim', $second], $main);
    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("refused {$second}: no capacity (board WIP limits reached)")
        ->and($s->kanban(['claim', $first], $main)->getExitCode())->toBe(3)
        ->and($s->ok(['claim', $second, '--force'], $main))->toStartWith("claimed {$second}");

    $card = $s->read($second);
    expect($card['stage'])->toBe('doing')
        ->and($card['claim'])->toMatchArray(['session' => 's1'])
        ->and(end($card['log']))->toMatchArray(['event' => 'stage', 'from' => 'ready', 'to' => 'doing', 'via' => 'start', 'by' => 'main']);
});
