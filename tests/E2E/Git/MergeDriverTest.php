<?php

use PetarSpasic\Kanban\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

/**
 * Runs `bin/kanban merge-driver %O %A %B %P` the way git does (no board, no lock) and returns [exit, %A after].
 *
 * @return array{0: int, 1: array<string, mixed>|string}
 */
function mergeDriver(?array $o, array|string $a, array|string $b, string $path = 'project/work/ACME-7K2M9Q.json'): array
{
    $dir = Sandbox::tmp();
    $files = [];
    foreach (['o' => $o, 'a' => $a, 'b' => $b] as $name => $content) {
        $files[$name] = "{$dir}/{$name}.json";
        file_put_contents($files[$name], $content === null ? '' : (is_string($content) ? $content : json_encode($content)));
    }
    $process = new Process([PHP_BINARY, Sandbox::package().'/bin/kanban', 'merge-driver', $files['o'], $files['a'], $files['b'], $path], $dir, ['XDEBUG_MODE' => 'off']);
    $process->run();
    $result = file_get_contents($files['a']);

    return [$process->getExitCode(), json_decode($result, true) ?? $result];
}

function baseCard(array $overrides = []): array
{
    return array_replace([
        'id' => 'ACME-7K2M9Q', 'type' => 'feature', 'title' => 'Original', 'stage' => 'ready', 'priority' => 'normal',
        'labels' => ['area:pdf', 'ui'], 'body' => 'Body', 'acceptance' => [['id' => 1, 'text' => 'One', 'done' => false], ['id' => 2, 'text' => 'Two', 'done' => false]],
        'depends_on' => [], 'blocked' => null, 'claim' => null, 'work' => null,
        'created' => '2026-09-28T10:00:00.000+00:00', 'updated' => '2026-09-28T10:00:00.000+00:00',
        'log' => [['id' => 'AAAAAAAA', 'at' => '2026-09-28T10:00:00.000+00:00', 'by' => 'owner', 'event' => 'created']],
    ], $overrides);
}

function logEntry(string $id, string $at, string $event = 'set'): array
{
    return ['id' => $id, 'at' => $at, 'by' => 'owner', 'event' => $event];
}

it('merges edits of different fields and unions the logs', function () {
    $o = baseCard();
    $a = baseCard(['priority' => 'high', 'updated' => '2026-09-28T11:00:00.000+00:00', 'log' => [...$o['log'], logEntry('BBBBBBBB', '2026-09-28T11:00:00.000+00:00')]]);
    $b = baseCard(['title' => 'Renamed', 'updated' => '2026-09-28T10:30:00.000+00:00', 'log' => [...$o['log'], logEntry('CCCCCCCC', '2026-09-28T10:30:00.000+00:00')]]);

    [$exit, $merged] = mergeDriver($o, $a, $b);

    expect($exit)->toBe(0)
        ->and($merged)->toMatchArray(['priority' => 'high', 'title' => 'Renamed', 'updated' => '2026-09-28T11:00:00.000+00:00'])
        ->and(array_column($merged['log'], 'id'))->toBe(['AAAAAAAA', 'CCCCCCCC', 'BBBBBBBB'])
        ->and(array_keys($merged))->toBe(array_keys($o));
});

it('takes the newer side on a true conflict of one field', function () {
    $o = baseCard();
    $a = baseCard(['title' => 'Mine', 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['title' => 'Theirs', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    expect(mergeDriver($o, $a, $b)[1]['title'])->toBe('Theirs')
        ->and(mergeDriver($o, $b, $a)[1]['title'])->toBe('Theirs');
});

it('merges sets three-way', function () {
    $o = baseCard(['depends_on' => ['ACME-AAAA00']]);
    $a = baseCard(['labels' => ['area:pdf', 'ui', 'urgent-fix'], 'depends_on' => []]);
    $b = baseCard(['labels' => ['area:pdf'], 'depends_on' => ['ACME-AAAA00', 'ACME-BBBB00']]);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged['labels'])->toBe(['area:pdf', 'urgent-fix'])
        ->and($merged['depends_on'])->toBe(['ACME-BBBB00']);
});

it('merges acceptance by id and renumbers a clashing new criterion', function () {
    $o = baseCard();
    $a = baseCard(['acceptance' => [['id' => 1, 'text' => 'One', 'done' => true], ['id' => 2, 'text' => 'Two', 'done' => false], ['id' => 3, 'text' => 'Mine', 'done' => false]]]);
    $b = baseCard(['acceptance' => [['id' => 1, 'text' => 'One, clearer', 'done' => false], ['id' => 3, 'text' => 'Theirs', 'done' => false]]]);

    [$exit, $merged] = mergeDriver($o, $a, $b);

    expect($exit)->toBe(0)->and($merged['acceptance'])->toBe([
        ['id' => 1, 'text' => 'One, clearer', 'done' => true],
        ['id' => 3, 'text' => 'Mine', 'done' => false],
        ['id' => 4, 'text' => 'Theirs', 'done' => false],
    ]);
});

it('keeps coupled fields together', function () {
    $claim = ['by' => 'dev@laptop', 'session' => 's1', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $o = baseCard();
    $a = baseCard(['stage' => 'doing', 'claim' => $claim, 'updated' => '2026-09-28T10:05:00.000+00:00']);
    $b = baseCard(['blocked' => 'Needs owner', 'priority' => 'high', 'updated' => '2026-09-28T10:01:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged)->toMatchArray(['stage' => 'doing', 'claim' => $claim, 'blocked' => null, 'priority' => 'high']);
});

it('refuses to merge two different cards on one path', function () {
    $a = baseCard();
    $b = baseCard(['created' => '2026-09-28T12:00:00.000+00:00', 'title' => 'Another card']);

    expect(mergeDriver(null, $a, $b)[0])->toBe(1)
        ->and(mergeDriver(null, $a, '{broken')[0])->toBe(1);
});

it('merges a card added on both sides (no ancestor: the newer side wins each difference) and board files by key', function () {
    $a = baseCard(['priority' => 'high', 'updated' => '2026-09-28T11:00:00.000+00:00']);
    $b = baseCard(['title' => 'Same card, other edit']);
    expect(mergeDriver(null, $a, $b)[1])->toMatchArray(['priority' => 'high', 'title' => 'Original']);

    $board = ['title' => 'Work', 'kind' => 'work', 'body' => '', 'order' => 10, 'wip' => ['doing' => 6], 'updated' => '2026-09-28T10:00:00.000+00:00'];
    [$exit, $merged] = mergeDriver($board,
        array_replace($board, ['order' => 30, 'updated' => '2026-09-28T11:00:00.000+00:00']),
        array_replace($board, ['wip' => ['doing' => 2]]),
        'project/work/board.json');
    expect($exit)->toBe(0)->and($merged)->toBe(array_replace($board, ['order' => 30, 'wip' => ['doing' => 2], 'updated' => '2026-09-28T11:00:00.000+00:00']));
});

it('keeps the claim that is already on the upstream side when both sides claimed the card', function () {
    $o = baseCard();
    $held = ['by' => 'alice@laptop', 'session' => 'session-a', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $late = ['by' => 'bob@desktop', 'session' => 'session-b', 'at' => '2026-09-28T10:20:00.000+00:00'];
    $a = baseCard(['stage' => 'doing', 'claim' => $held, 'work' => ['branch' => 'card/a'], 'updated' => '2026-09-28T10:05:00.000+00:00']);
    $b = baseCard(['stage' => 'doing', 'claim' => $late, 'work' => ['branch' => 'card/b'], 'updated' => '2026-09-28T10:20:00.000+00:00']);

    [$exit, $merged] = mergeDriver($o, $a, $b);

    expect($exit)->toBe(0)
        ->and($merged['claim'])->toBe($held)
        ->and($merged['work'])->toBe(['branch' => 'card/a'])
        ->and($merged['stage'])->toBe('doing');
});

it('still takes the newer side when only one side claimed', function () {
    $o = baseCard();
    $claim = ['by' => 'alice@laptop', 'session' => 'session-a', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $a = baseCard(['title' => 'Edited', 'updated' => '2026-09-28T10:30:00.000+00:00']);
    $b = baseCard(['stage' => 'doing', 'claim' => $claim, 'updated' => '2026-09-28T10:05:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged['claim'])->toBe($claim)->and($merged['title'])->toBe('Edited');
});

it('takes the newer side when only the local side replaced a claim the ancestor already had', function () {
    $held = ['by' => 'alice@laptop', 'session' => 'session-a', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $takeover = ['by' => 'bob@desktop', 'session' => 'session-b', 'at' => '2026-09-28T10:40:00.000+00:00'];
    $o = baseCard(['stage' => 'doing', 'claim' => $held, 'work' => ['branch' => 'card/a'], 'updated' => '2026-09-28T10:05:00.000+00:00']);
    $a = baseCard(['stage' => 'doing', 'claim' => $held, 'work' => ['branch' => 'card/a', 'head' => 'abc'], 'updated' => '2026-09-28T10:30:00.000+00:00']);
    $b = baseCard(['stage' => 'doing', 'claim' => $takeover, 'work' => ['branch' => 'card/b'], 'updated' => '2026-09-28T10:40:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged['claim'])->toBe($takeover);
});
