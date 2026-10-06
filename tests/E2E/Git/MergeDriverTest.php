<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

/**
 * Runs `bin/kanban merge-driver %O %A %B %P` the way git does (no board, no lock) and returns [exit, %A after].
 *
 * @return array{0: int, 1: array<string, mixed>|string}
 */
function mergeDriver(?array $o, array|string $a, array|string $b, string $path = 'work/ACME-7K2M9Q.json'): array
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
        ->and(array_column($merged['log'], 'event'))->not->toContain('conflict')
        ->and(array_keys($merged))->toBe(array_keys($o));
});

it('takes the newer side on a true conflict of one field', function () {
    $o = baseCard();
    $a = baseCard(['title' => 'Mine', 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['title' => 'Theirs', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    expect(mergeDriver($o, $a, $b)[1]['title'])->toBe('Theirs')
        ->and(mergeDriver($o, $b, $a)[1]['title'])->toBe('Theirs');
});

/** @return list<array<string, mixed>> */
function conflictEntries(array $card): array
{
    return array_values(array_filter($card['log'], fn (array $entry) => $entry['event'] === 'conflict'));
}

it('writes the text a true conflict displaces into the log, the same record from either side', function () {
    $o = baseCard();
    $a = baseCard(['title' => 'Mine', 'body' => 'My words', 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['title' => 'Theirs', 'body' => 'Their words', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);
    [, $swapped] = mergeDriver($o, $b, $a);

    $records = conflictEntries($merged);
    $byField = array_column($records, 'lost', 'field');
    ksort($byField);
    expect($merged)->toMatchArray(['title' => 'Theirs', 'body' => 'Their words'])
        ->and($byField)->toBe(['body' => 'My words', 'title' => 'Mine'])
        ->and(count($records))->toBe(2)
        ->and($records[0])->toMatchArray(['by' => 'hook', 'event' => 'conflict', 'at' => '2026-09-28T10:20:00.000+00:00'])
        ->and($records[0]['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{8}$/')
        ->and(conflictEntries($swapped))->toBe($records)
        ->and($swapped)->toBe($merged);
});

it('merges a plan written on one side with an edit on the other, and records the plan a true conflict displaces', function () {
    $o = baseCard(['stage' => 'planning']);
    $mine = "## Files\n- read `README.md` — mine";
    $a = baseCard(['stage' => 'planning', 'plan' => $mine, 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $edited = baseCard(['stage' => 'planning', 'priority' => 'high', 'updated' => '2026-09-28T10:20:00.000+00:00']);
    $theirs = baseCard(['stage' => 'planning', 'plan' => "## Files\n- read `README.md` — theirs", 'updated' => '2026-09-28T10:20:00.000+00:00']);

    [, $clean] = mergeDriver($o, $a, $edited);
    [, $conflict] = mergeDriver($o, $a, $theirs);

    expect($clean)->toMatchArray(['plan' => $mine, 'priority' => 'high'])
        ->and(conflictEntries($clean))->toBe([])
        ->and($conflict['plan'])->toBe($theirs['plan'])
        ->and(array_map(fn ($e) => [$e['field'], $e['lost']], conflictEntries($conflict)))->toBe([['plan', $mine]]);
});

it('adds nothing when the merged result is merged with the same side again', function () {
    $o = baseCard();
    $a = baseCard(['title' => 'Mine', 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['title' => 'Theirs', 'updated' => '2026-09-28T10:20:00.000+00:00']);
    [, $merged] = mergeDriver($o, $a, $b);

    [$exit, $again] = mergeDriver($o, $a, $merged);

    expect($exit)->toBe(0)->and($again)->toBe($merged)->and(conflictEntries($again))->toHaveCount(1);
});

it('records a text displaced because its coupled group went to the other side', function () {
    $claim = ['by' => 'dev@laptop', 'session' => 's1', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $o = baseCard();
    $a = baseCard(['blocked' => 'Needs the owner', 'updated' => '2026-09-28T10:01:00.000+00:00']);
    $b = baseCard(['stage' => 'doing', 'claim' => $claim, 'updated' => '2026-09-28T10:05:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged)->toMatchArray(['stage' => 'doing', 'blocked' => null])
        ->and(array_map(fn ($e) => [$e['field'], $e['lost']], conflictEntries($merged)))->toBe([['blocked', 'Needs the owner']]);
});

it('records a stage move, claim and work head that a merge displaces', function () {
    $claim = ['by' => 'dev@laptop', 'session' => 's1', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $o = baseCard(['stage' => 'doing', 'claim' => $claim, 'work' => ['head' => null]]);
    $a = baseCard(['stage' => 'review', 'claim' => $claim, 'work' => ['head' => 'abcdef0123456'], 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['stage' => 'doing', 'claim' => $claim, 'work' => ['head' => null], 'blocked' => 'Waiting for the vendor', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged)->toMatchArray(['stage' => 'doing', 'blocked' => 'Waiting for the vendor'])
        ->and(array_map(fn ($e) => [$e['field'], $e['lost']], conflictEntries($merged)))->toBe([['flow', 'stage=review; claim=dev@laptop; work.head=abcdef0']])
        ->and(conflictEntries(mergeDriver($o, $b, $a)[1]))->toBe(conflictEntries($merged));
});

it('records an unblock that the merge takes back', function () {
    $claim = ['by' => 'dev@laptop', 'session' => 's1', 'at' => '2026-09-28T10:05:00.000+00:00'];
    $o = baseCard(['stage' => 'doing', 'claim' => $claim, 'blocked' => 'Waiting for the vendor']);
    $a = baseCard(['stage' => 'doing', 'claim' => $claim, 'blocked' => null, 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['stage' => 'review', 'claim' => $claim, 'blocked' => 'Waiting for the vendor', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    [, $merged] = mergeDriver($o, $a, $b);

    expect($merged)->toMatchArray(['stage' => 'review', 'blocked' => 'Waiting for the vendor'])
        ->and(array_map(fn ($e) => [$e['field'], $e['lost']], conflictEntries($merged)))->toBe([['blocked', '(the block was cleared)']])
        ->and(conflictEntries(mergeDriver($o, $b, $a)[1]))->toBe(conflictEntries($merged));
});

it('records nothing for one-sided changes, different fields, or a card added on both sides', function () {
    $o = baseCard();
    $a = baseCard(['title' => 'Only mine', 'updated' => '2026-09-28T10:10:00.000+00:00']);
    $b = baseCard(['priority' => 'high', 'updated' => '2026-09-28T10:20:00.000+00:00']);

    expect(conflictEntries(mergeDriver($o, $a, $b)[1]))->toBe([])
        ->and(conflictEntries(mergeDriver($o, $a, $o)[1]))->toBe([])
        ->and(conflictEntries(mergeDriver(null, baseCard(['title' => 'A', 'updated' => '2026-09-28T10:10:00.000+00:00']), baseCard(['title' => 'B']))[1]))->toBe([]);
});

it('keeps the person and unknown keys of log entries through a merge', function () {
    $entry = ['id' => 'BBBBBBBB', 'at' => '2026-09-28T10:30:00.000+00:00', 'by' => 'worker', 'who' => 'Ana', 'event' => 'set', 'fields' => ['priority'], 'flavour' => 'from a newer version'];
    $o = baseCard();
    $a = baseCard(['priority' => 'high', 'updated' => '2026-09-28T10:30:00.000+00:00', 'log' => [...$o['log'], $entry]]);
    $b = baseCard(['labels' => ['area:pdf'], 'updated' => '2026-09-28T10:40:00.000+00:00']);

    [$exit, $merged] = mergeDriver($o, $a, $b);

    expect($exit)->toBe(0)->and(array_slice($merged['log'], -1)[0])->toEqual($entry);
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

    $board = ['title' => 'Work', 'body' => '', 'order' => 10, 'wip' => ['doing' => 6], 'updated' => '2026-09-28T10:00:00.000+00:00'];
    [$exit, $merged] = mergeDriver($board,
        array_replace($board, ['order' => 30, 'updated' => '2026-09-28T11:00:00.000+00:00']),
        array_replace($board, ['wip' => ['doing' => 2]]),
        'work/board.json');
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
