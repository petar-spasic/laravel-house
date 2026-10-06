<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt] = $this->p->started('Invoice export');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    $this->p->enter($this->wt);
});

const OPEN = <<<'MD'
## Open question
Who may download an invoice: the account owner only, or every member of the team?
Example: Mia, a member of Acme's team, opens Billing and sees a Download button on each invoice, or none.
1. Owner only — members ask the owner for a copy
2. Every member — anyone on the team downloads it
Recommended: 2 — teams share billing work
MD;

const PROVISIONAL = <<<'MD'
## Provisional decision
Which date an export file names: the invoice date or the day of the export?
Example: an invoice from 3 March exported on 9 April is saved as invoice-2026-03-03.pdf, or as invoice-2026-04-09.pdf.
1. Invoice date — files sort by billing period
2. Export date — files sort by when they were made
Recommended: 1 — accountants look for the billing period
Taken: 1
MD;

function questionFile(ProtocolSandbox $p, string $wt, string $text): string
{
    @mkdir($wt.'/.tmp', 0775, true);
    file_put_contents($wt.'/.tmp/question.md', $text."\n");

    return '--question-file=.tmp/question.md';
}

function applyStop(ProtocolSandbox $p, string $wt): void
{
    $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $wt]))->mustRun();
}

it('refuses a question file that is not multiple choice', function (string $status, string $text, string $error) {
    $report = $this->p->in($this->wt, ['report', $this->id, "--status={$status}", '--summary=Done', questionFile($this->p, $this->wt, $text)]);

    expect($report->getExitCode())->toBe(2)
        ->and($report->getErrorOutput())->toContain($error);
})->with([
    'one option' => ['blocked', "## Open question\nWhich engine?\nExample: an invoice PDF\n1. Dompdf — simple\nRecommended: 1 — simple", 'needs 2 to 4 numbered options'],
    'five options' => ['blocked', "## Open question\nWhich engine?\nExample: an invoice PDF\n1. A — a\n2. B — b\n3. C — c\n4. D — d\n5. E — e\nRecommended: 1 — a", 'needs 2 to 4 numbered options'],
    'recommended out of range' => ['blocked', "## Open question\nWhich engine?\nExample: an invoice PDF\n1. A — a\n2. B — b\nRecommended: 3 — c", 'Recommended: names no option'],
    'taken on an open question' => ['blocked', "## Open question\nWhich engine?\nExample: an invoice PDF\n1. A — a\n2. B — b\nRecommended: 1 — a\nTaken: 1", 'Taken: belongs to a Provisional decision'],
    'no context' => ['blocked', "## Open question\n1. A — a\n2. B — b\nRecommended: 1 — a", 'says what is decided'],
    'an open question in a review report' => ['review', OPEN, 'an Open question blocks the card: report --status=blocked'],
    'no section' => ['blocked', 'Which engine?', 'no ## Open question or ## Provisional decision section'],
    'no example' => ['blocked', "## Open question\nWhich engine?\n1. A — a\n2. B — b\nRecommended: 1 — a", 'an `Example:` line before the options'],
]);

it('writes a blocked report\'s Open question into the body and blocks the card on it', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', questionFile($this->p, $this->wt, OPEN)])->mustRun();
    applyStop($this->p, $this->wt);

    $card = $this->p->card($this->id);
    expect($card['blocked'])->toBe('question: Who may download an invoice: the account owner only, or every member of the team?')
        ->and($card['body'])->toStartWith('Build it')
        ->and($card['body'])->toContain('## Open question ('.gmdate('Y-m-d').")\nWho may download an invoice")
        ->and($card['body'])->toContain('Recommended: 2 — teams share billing work');
});

it('records a provisional decision once, however often the worker reports it', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: export");
    foreach ([1, 2] as $round) {
        $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done', questionFile($this->p, $this->wt, PROVISIONAL)])->mustRun();
        applyStop($this->p, $this->wt);
        $this->p->hook('subagent-start', $this->p->payload('subagent-start'));
    }

    $card = $this->p->card($this->id);
    expect($card['stage'])->toBe('review')
        ->and($card['blocked'])->toBeNull()
        ->and(substr_count($card['body'], '## Provisional decision'))->toBe(1);
});

it('lists open questions, blocking ones first, with older free-form ones raw', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: export");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done', questionFile($this->p, $this->wt, PROVISIONAL)])->mustRun();
    applyStop($this->p, $this->wt);
    $old = $this->p->sandbox->card('Seat limits', ['--body=Seats.'."\n\n## Open question\nShould a seat limit block invites? It would stop growth.", '--label=area:seats']);
    $this->p->sandbox->ok(['set', $old, 'blocked=question: should a seat limit block invites?']);
    $bare = $this->p->sandbox->card('Trial length', ['--label=area:trials']);
    $this->p->sandbox->ok(['set', $bare, 'blocked=question: 14 or 30 days?']);

    $out = $this->p->sandbox->ok('questions');

    $provisional = implode("\n", [
        "{$this->id}#1 provisional decision: Invoice export",
        '  Which date an export file names: the invoice date or the day of the export?',
        '  Example: an invoice from 3 March exported on 9 April is saved as invoice-2026-03-03.pdf, or as invoice-2026-04-09.pdf.',
        '  1. Invoice date — files sort by billing period (recommended, taken)',
        '  2. Export date — files sort by when they were made',
        '2 open, 1 provisional: `kanban answer <ID>#<n> <option> [--note=…]`',
    ])."\n";
    expect($out)->toContain("{$old}#1 open question: Seat limits (free-form: answer with --note)\n  Should a seat limit block invites? It would stop growth.\n")
        ->and($out)->toContain("{$bare} open question: Trial length (free-form: answer with --note)\n  14 or 30 days?\n")
        ->and($out)->toEndWith($provisional);
});

it('leaves out a finished card\'s open questions but keeps its provisional decisions, one count everywhere', function () {
    $done = $this->id;
    $this->p->sandbox->ok(['set', $done, 'body=@-', '--force'], ['KANBAN_SESSION' => 's1'], "Build it\n\n".OPEN."\n\n".PROVISIONAL."\n");
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$done}: export");
    $this->p->in($this->wt, ['report', $done, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    applyStop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $done, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e1', 'type' => 'kanban-evaluator']))->mustRun();
    $this->p->sandbox->ok(['finish', $done], ['KANBAN_SESSION' => 's1']);
    $asks = $this->p->sandbox->card('Seat limits', ['--body=Seats.'."\n\n".OPEN, '--label=area:seats']);
    $this->p->sandbox->ok(['set', $asks, 'blocked=question: who may download an invoice?']);

    $questions = $this->p->sandbox->ok('questions');

    expect($questions)->toContain("{$asks}#1 open question: Seat limits\n")
        ->and($questions)->toContain("{$done}#2 provisional decision: Invoice export\n")
        ->and($questions)->not->toContain("{$done}#1")
        ->and($questions)->toEndWith("1 open, 1 provisional: `kanban answer <ID>#<n> <option> [--note=…]`\n")
        ->and($this->p->sandbox->ok('status'))->toContain('· questions 1 open, 1 provisional')
        ->and($this->p->sandbox->ok(['morning']))->toContain("\nquestions 1 open, 1 provisional: `kanban questions`\n");
});

it('answers an open question: the answer goes under it, the block clears and the card is planned again with it', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', questionFile($this->p, $this->wt, OPEN)])->mustRun();
    applyStop($this->p, $this->wt);
    $this->p->sandbox->ok(['stop', $this->id, '--to=backlog']);

    $out = $this->p->sandbox->ok(['answer', "{$this->id}#1", '2', '--note=Members see only their own team.']);

    $card = $this->p->card($this->id);
    expect($out)->toContain("{$this->id}#1 answered: 2. Every member — anyone on the team downloads it")
        ->and($out)->toContain("promoted {$this->id} to planning")
        ->and($card['stage'])->toBe('planning')
        ->and($card['blocked'])->toBeNull()
        ->and($card['body'])->toContain("Recommended: 2 — teams share billing work\n\n## Owner answer (".gmdate('Y-m-d').")\n2. Every member — anyone on the team downloads it\n\nMembers see only their own team.")
        ->and($this->p->sandbox->ok('questions'))->toBe("no open questions\n");
});

it('answers a provisional decision on a card in a locked stage, and names a different choice', function () {
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: export");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done', questionFile($this->p, $this->wt, PROVISIONAL)])->mustRun();
    applyStop($this->p, $this->wt);

    $out = $this->p->sandbox->ok(['answer', $this->id, '2']);

    expect($out)->toBe("{$this->id}#1 answered: 2. Export date — files sort by when they were made\n"
        ."{$this->id}#1: the agent took 1; a follow-up card makes the change\n")
        ->and($this->p->card($this->id)['stage'])->toBe('review')
        ->and($this->p->card($this->id)['body'])->toContain("Taken: 1\n\n## Owner answer (".gmdate('Y-m-d').")\n2. Export date");
});

it('refuses an answer that names no option, or a question that is not open', function () {
    $this->p->in($this->wt, ['report', $this->id, '--status=blocked', questionFile($this->p, $this->wt, OPEN)])->mustRun();
    applyStop($this->p, $this->wt);

    expect($this->p->sandbox->kanban(['answer', "{$this->id}#1", '3'])->getErrorOutput())->toContain("{$this->id}#1 has options 1-2")
        ->and($this->p->sandbox->kanban(['answer', "{$this->id}#1"])->getErrorOutput())->toContain('give the option number')
        ->and($this->p->sandbox->kanban(['answer', "{$this->id}#2", '1'])->getErrorOutput())->toContain("{$this->id} has no open question #2");
});

it('lists the morning: merged cards, cards blocked without a question, and open questions', function () {
    $stuck = $this->p->sandbox->card('Stuck', ['--label=area:stuck']);
    $this->p->sandbox->ok(['set', $stuck, 'blocked=the payment sandbox is down']);
    $asks = $this->p->sandbox->card('Asks', ['--label=area:asks']);
    $this->p->sandbox->ok(['set', $asks, 'blocked=question: monthly or yearly?']);
    $this->p->commit($this->wt, 'app.php', "<?php\n", "{$this->id}: export");
    $this->p->in($this->wt, ['report', $this->id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    applyStop($this->p, $this->wt);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'e1', 'type' => 'kanban-evaluator']));
    $this->p->enter($this->wt, 'e1', 'kanban-evaluator');
    $this->p->in($this->wt, ['verdict', $this->id, 'approve', '--check=1:pass:ok', '--check=2:pass:ok'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $this->wt, 'agent' => 'e1', 'type' => 'kanban-evaluator']))->mustRun();
    $this->p->sandbox->ok(['finish', $this->id], ['KANBAN_SESSION' => 's1']);

    $runs = $this->p->runtime('runs.jsonl');
    @mkdir(dirname($runs), 0775, true);
    file_put_contents($runs, implode("\n", [
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'ended' => '2020-01-01T00:00:00.000+00:00', 'tokens' => 999, 'cost_usd' => 9.0]),
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'ended' => gmdate('Y-m-d\TH:i:s.000+00:00'), 'tokens' => 1_200_000, 'cost_usd' => 3.5]),
        json_encode(['card' => $this->id, 'type' => 'kanban-evaluator', 'ended' => gmdate('Y-m-d\TH:i:s.000+00:00'), 'tokens' => 300_000, 'cost_usd' => 1.25]),
    ])."\n");

    $out = $this->p->sandbox->ok(['morning', '--since=24h']);

    expect($out)->toMatch('/^since \d{4}-\d\d-\d\d \d\d:\d\dZ:$/m')
        ->and($out)->toContain("merged 1:\n  {$this->id} Invoice export")
        ->and($out)->toContain("blocked 1:\n  {$stuck} Stuck: the payment sandbox is down")
        ->and($out)->toContain('questions 1 open: `kanban questions`')
        ->and($out)->toContain('agents 2 runs, 1.5M tokens, $4.75 at list price; 1.5M tokens, $4.75 per merged card')
        ->and($out)->toStartWith('Kanban ACME:');
});

it('counts each resumed run at its own cost, though an older log holds the session\'s running total', function () {
    $runs = $this->p->runtime('runs.jsonl');
    @mkdir(dirname($runs), 0775, true);
    $now = gmdate('Y-m-d\TH:i:s.000+00:00');
    file_put_contents($runs, implode("\n", [
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'session' => 's1', 'ended' => $now, 'tokens' => 1000, 'cost_usd' => 2.0]),
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'session' => 's1', 'ended' => $now, 'tokens' => 1000, 'cost_usd' => 3.0]),
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'session' => 's1', 'ended' => $now, 'tokens' => 1000, 'cost_usd' => 4.5]),
        json_encode(['card' => $this->id, 'type' => 'kanban-worker', 'session' => 's1', 'ended' => $now, 'tokens' => 1000, 'cost_usd' => 0.5, 'session_cost_usd' => 5.0]),
    ])."\n");

    expect($this->p->sandbox->ok(['morning']))->toContain('agents 4 runs, 4k tokens, $5.00 at list price');
});
