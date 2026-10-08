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

it('files a discovered card on the filing card\'s area, names the cards in flight, and lists it in the morning until it has criteria', function () {
    $p = $this->p;
    [$other] = $p->started('Signature pad');
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=bug: Totals round wrong'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]))->mustRun();
    $filed = discoveredCards($p)[0];
    $areas = array_values(array_filter($p->card($this->id)['labels'], fn (string $l) => str_starts_with($l, 'area:')));

    expect($areas)->not->toBe([])
        ->and($filed['labels'])->toBe([...$areas, 'discovered'])
        ->and($p->in($this->wt, ['context'])->getOutput())->toContain("in flight (never file what one of these covers): {$other} Signature pad (doing)\n")
        ->and($p->sandbox->ok('morning'))->toContain("discovered, waiting for criteria 1:\n  {$filed['id']} Totals round wrong\n");

    $p->sandbox->ok(['set', $filed['id'], 'accept+=Totals round half up']);
    expect($p->sandbox->ok('morning'))->toContain("discovered, waiting for criteria 0\n");
});

it('files a failure already on main as one high-priority main-red card on no area, notes the next find on it, and names main red in the context', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test --compact — NotesTest fatals: the helper is declared twice'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $filed = discoveredCards($p);
    expect($filed)->toHaveCount(1)
        ->and($filed[0])->toMatchArray(['type' => 'bug', 'priority' => 'high', 'stage' => 'backlog', 'labels' => ['discovered', 'main-red'], 'title' => 'main red: php artisan test --compact'])
        ->and($filed[0]['acceptance'][0]['text'] ?? null)->toBe('`php artisan test --compact` passes on main')
        ->and($filed[0]['body'])->toContain('NotesTest fatals: the helper is declared twice');

    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Still waiting', '--discovered=main: vendor/bin/pest — the same fatal'])->mustRun();
    $second = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $note = collect($p->card($filed[0]['id'])['log'])->last();
    expect(discoveredCards($p))->toHaveCount(1)
        ->and($note)->toMatchArray(['event' => 'note', 'by' => 'worker'])
        ->and($note['text'])->toBe("{$this->id} found it too: `vendor/bin/pest` — the same fatal")
        ->and($second->getErrorOutput())->toContain("already on the board: {$filed[0]['id']}")
        ->and($p->in($this->wt, ['context'])->getOutput())
        ->toContain("failing on main already (not yours to file; subtract them from yours): {$filed[0]['id']} main red: php artisan test --compact\n");

    $main = trim($p->git($p->main, 'rev-parse', 'HEAD'));
    file_put_contents($p->runtime('main-check.json'), json_encode(['sha' => $main, 'after' => 'ACME-7K2QF9', 'command' => 'php artisan test --compact',
        'tail' => 'Tests: 1 failed', 'card' => $filed[0]['id'], 'at' => '2026-10-08T09:00:00Z']));
    expect($p->in($this->wt, ['context'])->getOutput())
        ->toContain('main red: `php artisan test --compact` fails since ACME-7K2QF9 merged ('.substr($main, 0, 7)."), bug card {$filed[0]['id']}: not yours to file; subtract its failures from yours\n");
});

it('ends a main: command at its dash or its closing backtick, and splits any other item at its first separator', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting',
        '--discovered=main: npm test -- --run e2e — the login spec fails',
        '--discovered=bug: npm run lint -- --fix reorders imports — it breaks the barrel files',
        '--discovered=chore: Prune the fixtures — they outgrew the suite -- by far'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $filed = array_column(discoveredCards($p), 'body', 'title');
    expect($filed)->toHaveKey('main red: npm test -- --run e2e')
        ->and($filed['main red: npm test -- --run e2e'])->toContain('the login spec fails')
        ->and($filed)->toHaveKey('npm run lint')
        ->and($filed['npm run lint'])->toContain('--fix reorders imports — it breaks the barrel files')
        ->and($filed)->toHaveKey('Prune the fixtures')
        ->and($filed['Prune the fixtures'])->toContain('they outgrew the suite -- by far');
});

it('takes a backticked main: command whole, its body after any separator, and refuses a stray backtick', function (string $item, string $title, string $body) {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', "--discovered={$item}"])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $filed = discoveredCards($p);
    expect($filed)->toHaveCount(1)
        ->and($filed[0]['title'])->toBe($title)
        ->and($filed[0]['body'])->toEndWith($body);
})->with([
    'ascii' => ['main: `php artisan test` -- NotesTest fatals', 'main red: php artisan test', 'NotesTest fatals'],
    'dash' => ['main: `npm test -- --run e2e` — the login spec fails', 'main red: npm test -- --run e2e', 'the login spec fails'],
    'none' => ['main: `vendor/bin/pest`', 'main red: vendor/bin/pest', 'fails on main.'],
]);

it('refuses a main: item whose command quotes only part of itself in backticks, or never closes its backtick', function (string $item, string $why) {
    $report = $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', "--discovered={$item}"]);

    expect($report->getExitCode())->not->toBe(0)
        ->and($report->getErrorOutput())->toContain($why);
})->with([
    ['main: php artisan `test` — fatals', 'quote the whole command in backticks, or none of it'],
    ['main: `php artisan test — fatals', 'backtick is never closed'],
]);

it('lists a main-red card the agents filed in backlog with no area in the morning and the session brief, once, until it has an area', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test — NotesTest fatals'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    $red = discoveredCards($p)[0]['id'];
    $line = "failing on main, in backlog with no area (give it an area and promote it first): {$red} main red: php artisan test\n";

    expect(substr_count($p->sandbox->ok('morning'), $line))->toBe(1)
        ->and(substr_count($p->hook('session-start', $p->payload('session-start'))->getOutput(), $line))->toBe(1);

    $p->sandbox->ok(['set', $red, 'labels=+area:notes']);
    expect($p->sandbox->ok('morning'))->not->toContain('failing on main, in backlog');
});

it('notes a failure already on main once when the report that found it is applied again', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test --compact — NotesTest fatals'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    $red = discoveredCards($p)[0]['id'];
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Still waiting', '--discovered=main: vendor/bin/pest — the same fatal'])->mustRun();
    touch($p->runtime('agents/a4d2c0ffee.json'), time() - 30 * 60);
    // the report's own write fails after the note landed
    $file = glob($p->sandbox->root."/docs/kanban/*/{$this->id}.json")[0];
    $bytes = file_get_contents($file);
    file_put_contents($file, json_encode(['bogus' => true] + json_decode($bytes, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    expect($p->sandbox->ok(['apply', $this->id]))->toContain('report not applied');
    file_put_contents($file, $bytes);

    expect($p->sandbox->ok(['apply', $this->id]))->toContain("{$this->id}: report applied")
        ->and(array_filter($p->card($red)['log'], fn ($e) => $e['event'] === 'note'))->toHaveCount(1);
});
