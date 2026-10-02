<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
});

it('promotes through the ready policy and names every refusal', function () {
    $s = $this->sandbox;
    $bare = $s->card('Bare');
    $good = $s->card('Good', ['--body=Do it', '--accept=Works', '--label=area:api']);
    $arealess = $s->card('Arealess', ['--body=Do it', '--accept=Works', '--label=ux']);
    $blocked = $s->card('Blocked', ['--body=Do it', '--accept=Works', '--label=area:ui']);
    $s->ok(['set', $blocked, 'blocked=owner']);
    $asks = $s->card('Asks', ['--body=Do it', '--accept=Works', '--label=area:billing']);
    $s->ok(['set', $asks, 'blocked=question: monthly or yearly plans?']);
    $dropped = $s->card('Dropped dep');
    $s->ok(['move', $dropped, 'dropped', '--reason=no']);
    $dependent = $s->card('Dependent', ['--body=Do it', '--accept=Works', '--label=area:pdf', "--depends={$dropped}"]);
    $ready = $s->readyCard('Already ready');

    $run = $s->kanban(['promote', $bare, $good, $arealess, $blocked, $asks, $dependent, $ready]);

    expect($run->getExitCode())->toBe(3)
        ->and($run->getOutput())->toBe(implode("\n", [
            "refused {$bare}: R1 no area:* label; R3 empty body; R4 no acceptance criteria",
            "promoted {$good}",
            "refused {$arealess}: R1 no area:* label",
            "refused {$blocked}: R6 blocked: owner",
            "refused {$asks}: R6 blocked: question: monthly or yearly plans?",
            "refused {$dependent}: R5 dependency {$dropped} is dropped",
            "refused {$ready}: R7 in ready, not backlog",
        ])."\n")
        ->and($s->read($good)['stage'])->toBe('ready')
        ->and($s->read($arealess)['stage'])->toBe('backlog')
        ->and($s->read($asks)['stage'])->toBe('backlog');
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
    $low = $s->card('Low', ['--body=x', '--accept=y', '--priority=low', '--label=area:a']);
    $high = $s->card('High', ['--body=x', '--accept=y', '--priority=high', '--label=area:b']);
    $s->card('Not ready yet');
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"ready_buffer": 12', '"ready_buffer": 1', file_get_contents($s->root.'/docs/kanban/kanban.json')));

    expect($s->ok(['promote', '--auto']))->toBe("promoted {$high}\nready 1/1 startable\n")
        ->and($s->read($low)['stage'])->toBe('backlog');
});

it('orders next by the pull policy', function (Closure $setup, array|string $expected) {
    $ids = $setup($this->sandbox);

    $output = $this->sandbox->ok(['next', '--count=10']);

    $actual = is_string($expected) ? strtok($output, "\n") : array_values(array_map(
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
    'only a done dependency is satisfied' => [function (Sandbox $s) {
        $done = $s->card('Shipped');
        $card = $s->read($done);
        $card['stage'] = 'done';
        $card['work'] = ['branch' => 'card/shipped'];
        file_put_contents($s->root."/docs/kanban/project/work/{$done}.json", json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $ready = $s->readyCard('Still in ready');

        return ['r' => $ready, 'd' => $s->readyCard('D', ["--depends={$done}"]), 'e' => $s->readyCard('E', ["--depends={$ready}"])];
    }, ['r', 'd']],
    'a ready card that lost its area waits' => [function (Sandbox $s) {
        $id = $s->readyCard('Unscoped', ['--label=area:x']);
        $s->ok(['set', $id, 'labels=-area:x']);

        return ['u' => $id, 'k' => $s->readyCard('K')];
    }, ['k']],
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

it('says why each ready card waits: under none, with -v, in status and in the brief', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    $busy = $s->readyCard('Busy', ['--label=area:pdf']);
    $s->ok(['claim', $busy], $main);
    $dep = $s->card('Dep');
    $waits = $s->readyCard('Waits', ["--depends={$dep}"]);
    $blocked = $s->readyCard('Blocked');
    $s->ok(['set', $blocked, 'blocked=waiting on the design']);
    $area = $s->readyCard('Same area', ['--label=area:pdf']);

    $expected = [
        "skipped {$waits} waits on {$dep} (backlog)",
        "skipped {$blocked} blocked: waiting on the design",
        "skipped {$area} area:pdf busy ({$busy} doing)",
    ];
    expect(explode("\n", trim($s->ok('next'))))->toBe(['none: no startable ready cards', ...$expected]);

    $free = $s->readyCard('Free');
    expect($s->ok('next'))->toStartWith($free)->not->toContain('skipped')
        ->and(explode("\n", trim($s->ok(['next', '-v']))))->toHaveCount(4)->toContain(...$expected)
        ->and(json_decode($s->ok(['status', '--json']), true)['skipped'])->toBe([$waits => "waits on {$dep} (backlog)", $blocked => 'blocked: waiting on the design', $area => "area:pdf busy ({$busy} doing)"])
        ->and($s->ok('status'))->toContain("skipped: {$waits} waits on {$dep} (backlog); {$blocked} blocked: waiting on the design; {$area} area:pdf busy ({$busy} doing)\n");

    $refused = $s->kanban(['claim', $area], $main);
    expect($refused->getExitCode())->toBe(3)->and($refused->getErrorOutput())->toContain("refused {$area}: area:pdf busy ({$busy} doing)");
});

it('fills the ready buffer with startable cards only, and names each backlog card it passes over', function () {
    $s = $this->sandbox;
    $main = ['KANBAN_SESSION' => 's1'];
    $busy = $s->readyCard('Busy', ['--label=area:pdf']);
    $s->ok(['claim', $busy], $main);
    $s->ok(['board', 'project/work', '--wip-doing=1']);
    $waiting = $s->readyCard('Waits too', ["--depends={$busy}"]);
    $later = $s->card('After busy', ['--body=x', '--accept=y', '--label=area:a1x', "--depends={$busy}", '--priority=high']);
    $pdf = $s->card('More pdf', ['--body=x', '--accept=y', '--label=area:pdf', '--priority=high']);
    $bare = $s->card('No criteria', ['--priority=high']);
    $next = $s->card('Next', ['--body=x', '--accept=y', '--label=area:a2x']);
    file_put_contents($s->root.'/docs/kanban/kanban.json', str_replace('"ready_buffer": 12', '"ready_buffer": 1', file_get_contents($s->root.'/docs/kanban/kanban.json')));

    $out = $s->ok(['promote', '--auto']);

    expect($out)->toContain("skipped {$later}: waits on {$busy} (doing)\n")
        ->and($out)->toContain("skipped {$pdf}: area:pdf busy ({$busy} doing)\n")
        ->and($out)->toContain("skipped {$bare}: R")
        ->and($out)->toEndWith("promoted {$next}\nready 1/1 startable\n")
        ->and(array_map(fn (string $id) => $s->read($id)['stage'], [$waiting, $later, $pdf, $bare, $next]))->toBe(['ready', 'backlog', 'backlog', 'backlog', 'ready']);
});

it('clears what blocked a card when it is stopped back to ready, and logs it', function () {
    $code = \PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox::create();
    $id = $code->started('Picked up');
    $code->ok(['set', $id, 'blocked=start failed: the stack would not build']);
    $code->ok(['stop', $id, '--to=ready']);

    $card = $code->sandbox->read($id);
    expect($card['blocked'])->toBeNull()
        ->and(end($card['log']))->toMatchArray(['event' => 'stage', 'to' => 'ready', 'via' => 'stop', 'unblocked' => 'start failed: the stack would not build']);

    $code->ok(['start', $id]);
    $code->ok(['set', $id, 'blocked=waiting on the owner']);
    $code->ok(['stop', $id, '--to=backlog']);
    expect($code->sandbox->read($id)['blocked'])->toBe('waiting on the owner');
});
