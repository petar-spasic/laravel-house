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
    // merged on the first approval
    $p->sandbox->ok(['move', $this->id, 'done', '--force'], ['KANBAN_SESSION' => 's1']);

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

/** @return list<array<string, mixed>> the card's `main_red` log entries */
function mainRedEntries(ProtocolSandbox $p, string $id): array
{
    return array_values(array_filter($p->card($id)['log'], fn (array $e) => $e['event'] === 'main_red'));
}

it('files no card for a failure already on main: it goes on the card for the main session, once, and the context names it', function () {
    $p = $this->p;
    [, $other] = $p->started('Signature pad');
    $base = trim($p->git($p->main, 'rev-parse', 'main'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test --compact — NotesTest fatals: the helper is declared twice'])->mustRun();
    $first = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $line = 'main is red: `php artisan test --compact` fails on main at '.substr($base, 0, 7).", reported by the worker of {$this->id}: NotesTest fatals: the helper is declared twice; fix it on main and `vendor/bin/kanban publish`\n";
    expect(discoveredCards($p))->toBe([])
        ->and(mainRedEntries($p, $this->id))->sequence(fn ($e) => $e->toMatchArray(['by' => 'worker', 'command' => 'php artisan test --compact', 'base' => $base,
            'body' => 'NotesTest fatals: the helper is declared twice']))
        ->and($first->getErrorOutput())->toContain('1 failure(s) on main for the main session')
        ->and($p->in($other, ['context'])->getOutput())
        ->toContain('failing on main already (not yours to report; subtract it from yours): `php artisan test --compact` at '.substr($base, 0, 7)."\n")
        ->and(substr_count($p->sandbox->ok('morning'), $line))->toBe(1)
        ->and(substr_count($p->hook('session-start', $p->payload('session-start'))->getOutput(), $line))->toBe(1);

    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Still waiting', '--discovered=main: php artisan test --compact — the same fatal'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    expect(mainRedEntries($p, $this->id))->toHaveCount(1)
        ->and(discoveredCards($p))->toBe([]);
});

it('drops a failure on main from a clone that does not hold main as this machine knows it', function () {
    $p = $this->p;
    $p->git($p->main, 'commit', '--allow-empty', '-qm', 'Fix main');
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test — NotesTest fatals'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    expect(mainRedEntries($p, $this->id))->toBe([])
        ->and($p->sandbox->ok('morning'))->not->toContain('main is red');
});

it('ends a main: command at its dash or its closing backtick, and splits any other item at its first separator', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting',
        '--discovered=main: npm test -- --run e2e — the login spec fails',
        '--discovered=bug: npm run lint -- --fix reorders imports — it breaks the barrel files',
        '--discovered=chore: Prune the fixtures — they outgrew the suite -- by far'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $filed = array_column(discoveredCards($p), 'body', 'title');
    expect(mainRedEntries($p, $this->id))->sequence(fn ($e) => $e->toMatchArray(['command' => 'npm test -- --run e2e', 'body' => 'the login spec fails']))
        ->and($filed)->toHaveCount(2)
        ->and($filed)->toHaveKey('npm run lint')
        ->and($filed['npm run lint'])->toContain('--fix reorders imports — it breaks the barrel files')
        ->and($filed)->toHaveKey('Prune the fixtures')
        ->and($filed['Prune the fixtures'])->toContain('they outgrew the suite -- by far');
});

it('takes a backticked main: command whole, its body after any separator, and refuses a stray backtick', function (string $item, string $command, ?string $body) {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', "--discovered={$item}"])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));

    $entries = mainRedEntries($p, $this->id);
    expect(discoveredCards($p))->toBe([])
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['command'])->toBe($command)
        ->and($entries[0]['body'] ?? null)->toBe($body);
})->with([
    'ascii' => ['main: `php artisan test` -- NotesTest fatals', 'php artisan test', 'NotesTest fatals'],
    'dash' => ['main: `npm test -- --run e2e` — the login spec fails', 'npm test -- --run e2e', 'the login spec fails'],
    'none' => ['main: `vendor/bin/pest`', 'vendor/bin/pest', null],
]);

it('refuses a main: item whose command quotes only part of itself in backticks, or never closes its backtick', function (string $item, string $why) {
    $report = $this->p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', "--discovered={$item}"]);

    expect($report->getExitCode())->not->toBe(0)
        ->and($report->getErrorOutput())->toContain($why);
})->with([
    ['main: php artisan `test` — fatals', 'quote the whole command in backticks, or none of it'],
    ['main: `php artisan test — fatals', 'backtick is never closed'],
]);

it('records a failure already on main once when the report that found it is applied again', function () {
    $p = $this->p;
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Waiting', '--discovered=main: php artisan test --compact — NotesTest fatals'])->mustRun();
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $this->wt]));
    $p->hook('subagent-start', $p->payload('subagent-start'));
    $p->in($this->wt, ['report', $this->id, '--status=blocked', '--reason=Still waiting', '--discovered=main: vendor/bin/pest — the same fatal'])->mustRun();
    touch($p->runtime('agents/a4d2c0ffee.json'), time() - 30 * 60);
    // the report's own write fails
    $file = glob($p->sandbox->root."/docs/kanban/*/{$this->id}.json")[0];
    $bytes = file_get_contents($file);
    file_put_contents($file, json_encode(['bogus' => true] + json_decode($bytes, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    expect($p->sandbox->ok(['apply', $this->id]))->toContain('report not applied');
    file_put_contents($file, $bytes);

    expect($p->sandbox->ok(['apply', $this->id]))->toContain("{$this->id}: report applied")
        ->and($p->sandbox->ok(['apply', $this->id]))->not->toContain('report applied')
        ->and(array_column(mainRedEntries($p, $this->id), 'command'))->toBe(['php artisan test --compact', 'vendor/bin/pest']);
});
