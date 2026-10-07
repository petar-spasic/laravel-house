<?php

use PetarSpasic\LaravelHouse\Tests\Support\ProtocolSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->p = ProtocolSandbox::create();
    [$this->id, $this->wt, $this->out] = $this->p->planning('Invoice export');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'p1', 'type' => 'kanban-planner']));
    $this->p->enter($this->wt, 'p1', 'kanban-planner');
});

const PLAN_OPEN = <<<'MD'
## Open question
Who may download an invoice: the account owner only, or every member of the team?
Example: Mia, a member of Acme's team, opens Billing and sees a Download button on each invoice, or none.
1. Owner only — members ask the owner for a copy
2. Every member — anyone on the team downloads it
Recommended: 2 — teams share billing work
MD;

const PLAN_PROVISIONAL = <<<'MD'
## Provisional decision
Which date an export file names: the invoice date or the day of the export?
Example: an invoice from 3 March exported on 9 April is saved as invoice-2026-03-03.pdf, or as invoice-2026-04-09.pdf.
1. Invoice date — files sort by billing period
2. Export date — files sort by when they were made
Recommended: 1 — accountants look for the billing period
Taken: 1
MD;

/** Writes the plan into the clone's .tmp and stages it, as the planner does. */
function stagePlan(ProtocolSandbox $p, string $wt, string $id, string $plan, array $options = []): Process
{
    @mkdir($wt.'/.tmp', 0775, true);
    file_put_contents($wt.'/.tmp/plan.md', $plan);

    return $p->in($wt, ['plan', $id, '--plan-file=.tmp/plan.md', ...$options]);
}

/** @return array{out: string, err: string, json: mixed} the planner's stop, which applies what it staged */
function plannerStop(ProtocolSandbox $p, string $wt, string $agent = 'p1'): array
{
    $process = $p->hook('subagent-stop', $p->payload('subagent-stop', ['cwd' => $wt, 'agent' => $agent, 'type' => 'kanban-planner']));

    return ['out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'json' => json_decode($process->getOutput(), true)];
}

/** @return list<array<string, mixed>> the card's log entries of $event, oldest first */
function logOf(array $card, string $event): array
{
    return array_values(array_filter($card['log'], fn (array $e) => $e['event'] === $event));
}

it('takes a planning card for its planner: held in planning with a clone of its own, and the planner spawn line', function () {
    $card = $this->p->card($this->id);

    expect($this->out)->toContain("started planning {$this->id}\n")
        ->and($this->out)->toContain('Agent(subagent_type="kanban-planner"')
        ->and($card['stage'])->toBe('planning')
        ->and($card['claim'])->not->toBeNull()
        ->and($card['work']['branch'])->toStartWith('card/')
        ->and(logOf($card, 'claimed'))->toHaveCount(1)
        ->and(logOf($card, 'claimed')[0])->toMatchArray(['for' => 'planning', 'by' => 'owner'])
        ->and($this->p->sandbox->ok('status'))->toContain('WIP doing 0 + planning 1/6, review 0/6 · planning 1 ·')
        ->and($this->p->sandbox->ok(['next', '--planning']))->toBe("none: no plannable cards\n");
});

it('stages a plan only from the card\'s clone, checked against its HEAD', function () {
    $main = $this->p->sandbox->kanban(['plan', $this->id, '--plan-file=-'], [], null, Sandbox::planFor([1, 2]));
    expect($main->getExitCode())->toBe(3)
        ->and($main->getErrorOutput())->toContain("{$this->id} is planning, being planned: a plan is given to a card waiting in planning");

    file_put_contents($this->wt.'/notes.md', "on disk, never committed\n");
    $untracked = stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2], 'notes.md'));
    expect($untracked->getExitCode())->toBe(2)
        ->and($untracked->getErrorOutput())->toContain('## Files: `notes.md` is tracked neither on main nor on the card\'s branch');

    $staged = stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]));
    expect($staged->getOutput())->toBe("staged plan for {$this->id}: 9 lines, 1 file\napplied when you stop\n")
        ->and($this->p->card($this->id))->not->toHaveKey('plan');
});

it('refuses a plan that misses what the worker needs, and says what to fix', function (string $plan, string $error) {
    $staged = stagePlan($this->p, $this->wt, $this->id, $plan);

    expect($staged->getExitCode())->toBe(2)
        ->and($staged->getErrorOutput())->toContain($error);
})->with([
    'empty' => ["\n", 'the plan is empty'],
    'no files' => ["## Steps\n1. Do it. Check: `true`\n\n## Criteria\n- 1: `true`\n- 2: `true`\n", 'the plan has no `## Files` section'],
    'no steps' => ["## Files\n- read `README.md` — x\n\n## Criteria\n- 1: `true`\n- 2: `true`\n", 'the plan has no `## Steps` section'],
    'a step unnumbered' => ["## Files\n- read `README.md` — x\n\n## Steps\n- Do it\n\n## Criteria\n- 1: `true`\n- 2: `true`\n", '## Steps has no numbered step'],
    'a file line without why' => ["## Files\n- read `README.md`\n\n## Steps\n1. Do it\n\n## Criteria\n- 1: `true`\n- 2: `true`\n", 'is not a file line: - create|change|delete|read `path` — why'],
    'a change to a file not there' => [Sandbox::planFor([1, 2], 'app/Missing.php'), '## Files: `app/Missing.php` is tracked neither on main nor on the card\'s branch'],
    'a create of a file there' => [str_replace('- read `README.md`', '- create `README.md`', Sandbox::planFor([1, 2])), '## Files: `README.md` already exists'],
    'a path out of the repository' => [Sandbox::planFor([1, 2], '../etc/hosts'), 'name one path relative to the repository root'],
    'a wildcard' => [Sandbox::planFor([1, 2], 'app/*.php'), 'without wildcards'],
    'a criterion left out' => [Sandbox::planFor([1]), '## Criteria has no line for criterion 2'],
    'a criterion the card lacks' => [Sandbox::planFor([1, 2, 3]), '## Criteria: 3 is not a criterion of the card (criteria: 1, 2)'],
    'a criterion twice' => [Sandbox::planFor([1, 2, 2]), '## Criteria: criterion 2 appears twice'],
    'a criterion without proof' => [str_replace('- 2: `true` → exit 0', '- 2: it works', Sandbox::planFor([1, 2])), '## Criteria: criterion 2 names no proof in a code span'],
    'past the storage limit' => [Sandbox::planFor([1, 2])."\n## Facts\n".str_repeat("- a fact\n", 2500), 'the limit is 20000: cut prose, keep the facts'],
]);

it('stages a plan that runs long or writes the code, with a hint for each, and refuses none of them', function (string $plan, string $hint) {
    $staged = stagePlan($this->p, $this->wt, $this->id, $plan);

    expect($staged->getExitCode())->toBe(0)
        ->and($staged->getOutput())->toContain("\nhint: {$hint}")
        ->and($staged->getOutput())->toEndWith("applied when you stop; to follow a hint, revise the plan and stage it again\n");
})->with([
    'long' => [Sandbox::planFor([1, 2])."\n## Facts\n".str_repeat("- a fact\n", 900), 'the plan is 8266 characters; most take 3000 to 8000: cut prose and code, keep the facts the worker cannot find quickly'],
    'many steps' => [str_replace("1. Build it. Check: `true` → exit 0\n", implode('', array_map(fn (int $n) => "{$n}. Part {$n}. Check: `true`\n", range(1, 21))), Sandbox::planFor([1, 2])),
        '## Steps has 21 steps; a step is one change the worker can check: merge the small ones'],
    'a method body' => [Sandbox::planFor([1, 2])."\n## Facts\n```php\n".str_repeat("\$total += \$line->cents;\n", 13)."```\n",
        '## Facts, line 12: a code block of 13 lines: the plan pins contracts (a signature, a route, columns) and names a file whose pattern to copy; the worker writes the code'],
    'a block left open' => [Sandbox::planFor([1, 2])."\n## Facts\n~~~\n".str_repeat("x\n", 13), '## Facts, line 12: a code block of 13 lines'],
    'code in many blocks' => [Sandbox::planFor([1, 2])."\n## Facts\n".str_repeat("```php\n".str_repeat("\$a = 1;\n", 11)."```\n", 4), 'code blocks hold 44 lines in all'],
    'code inline' => [Sandbox::planFor([1, 2])."\n## Facts\n- `".str_repeat('$rows[] = $row; ', 13)."`\n", '## Facts, line 12: an inline code span of 207 characters'],
    'a docker command' => [Sandbox::planFor([1, 2])."\n## Facts\n- e2e: `docker compose -f docker-compose.local.yml exec app docker/e2e.sh`\n",
        '## Facts, line 12: a docker command: the card\'s shell runs inside its container, which has no docker'],
]);

it('gives no hint for a compose file the plan names', function () {
    $staged = stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2])."\n## Facts\n- `docker-compose.local.yml` maps `docker/e2e.sh`; `docker run` is the image's own entrypoint test\n");

    expect($staged->getExitCode())->toBe(0)->and($staged->getOutput())->not->toContain('hint:');
});

it('applies the plan when its planner stops, and stop --to=ready takes the clone down and deletes the planning branch', function () {
    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();

    $stop = plannerStop($this->p, $this->wt);

    $card = $this->p->card($this->id);
    $branch = $card['work']['branch'];
    expect($stop['json'])->toBeNull()
        ->and($stop['err'])->toContain("{$this->id}: plan applied (9 lines)")
        ->and($card['stage'])->toBe('planning')
        ->and($card['plan'])->toBe(trim(Sandbox::planFor([1, 2])))
        ->and($card['claim'])->not->toBeNull()
        ->and(logOf($card, 'planned')[0])->toMatchArray(['by' => 'planner', 'base' => $card['work']['base'], 'head' => $card['work']['base']])
        ->and($this->p->sandbox->ok('status'))->toContain("planning {$this->id}")->toContain('planned, not yet moved to ready');

    $out = $this->p->sandbox->ok(['stop', $this->id, '--to=ready']);

    $card = $this->p->card($this->id);
    expect($out)->toContain("deleted branch {$branch}\n")->toContain("{$this->id} planning→ready\n")
        ->and($card)->toMatchArray(['stage' => 'ready', 'claim' => null, 'work' => null])
        ->and(end($card['log']))->toMatchArray(['event' => 'stage', 'from' => 'planning', 'to' => 'ready', 'via' => 'plan'])
        ->and(is_dir($this->wt))->toBeFalse()
        ->and(trim($this->p->git($this->p->main, 'branch', '--list', $branch)))->toBe('')
        ->and($this->p->sandbox->ok('next'))->toStartWith("{$this->id} ");
});

it('refuses ready before its planner\'s plan is on the card, and takes nothing down', function () {
    $refused = $this->p->sandbox->kanban(['stop', $this->id, '--to=ready']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$this->id} has no plan from its planner yet")
        ->and(is_dir($this->wt))->toBeTrue()
        ->and($this->p->card($this->id)['stage'])->toBe('planning')
        ->and($this->p->sandbox->kanban(['move', $this->id, 'backlog'])->getErrorOutput())
        ->toContain("{$this->id} is being planned: `kanban stop {$this->id} --to=backlog|dropped` takes its planner off it");
});

it('blocks a planner that stops without a plan, three times, then marks the card blocked', function () {
    foreach ([1, 2, 3] as $n) {
        expect(plannerStop($this->p, $this->wt)['json'])->toBe(['decision' => 'block', 'reason' => "No plan staged for {$this->id}. Write it to .tmp/plan.md, then run: vendor/bin/kanban plan {$this->id} --plan-file=.tmp/plan.md (blocked: --status=blocked --reason=\"…\", or an Open question in --question-file). If vendor/bin/kanban cannot reach the board, end your last message with why: after 3 refusals the stop goes through and the card is blocked"]);
    }

    expect(plannerStop($this->p, $this->wt)['out'])->toBe('')
        ->and($this->p->card($this->id))->toMatchArray(['stage' => 'planning', 'blocked' => 'planner stopped without a plan']);
});

it('refuses a staged plan once the card changed, so the planner plans the card as it is', function () {
    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();
    $this->p->sandbox->ok(['set', $this->id, 'body=Build it as a PDF']);

    $stop = plannerStop($this->p, $this->wt);

    expect($stop['json']['decision'])->toBe('block')
        ->and($stop['json']['reason'])->toContain("Plan for {$this->id} not applied: {$this->id} changed since the plan was staged (its criteria or body)")
        ->and($this->p->card($this->id))->not->toHaveKey('plan');

    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();
    expect(plannerStop($this->p, $this->wt)['json'])->toBeNull()
        ->and($this->p->card($this->id)['plan'])->toBe(trim(Sandbox::planFor([1, 2])));

    $this->p->sandbox->ok(['set', $this->id, 'body=Build it as a zip']);
    expect($this->p->sandbox->ok('status'))->toContain('planned, then the card changed: its planner revises it');
});

it('blocks the card on a planner\'s open question, and the main session parks it in the backlog', function () {
    @mkdir($this->wt.'/.tmp', 0775, true);
    file_put_contents($this->wt.'/.tmp/question.md', PLAN_OPEN."\n");
    $asReady = stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]), ['--question-file=.tmp/question.md']);
    expect($asReady->getExitCode())->toBe(2)
        ->and($asReady->getErrorOutput())->toContain('an Open question blocks the card: plan --status=blocked');

    $this->p->in($this->wt, ['plan', $this->id, '--status=blocked', '--question-file=.tmp/question.md'])->mustRun();
    plannerStop($this->p, $this->wt);

    $card = $this->p->card($this->id);
    expect($card)->toMatchArray(['stage' => 'planning', 'blocked' => 'question: Who may download an invoice: the account owner only, or every member of the team?'])
        ->and($card['body'])->toContain('## Open question ('.gmdate('Y-m-d').")\nWho may download an invoice")
        ->and(logOf($card, 'plan')[0])->toMatchArray(['status' => 'blocked', 'by' => 'planner']);

    $out = $this->p->sandbox->ok(['stop', $this->id, '--to=backlog']);
    expect($out)->toContain("{$this->id} planning→backlog")
        ->and($this->p->card($this->id))->toMatchArray(['stage' => 'backlog', 'claim' => null, 'work' => null])
        ->and($this->p->card($this->id)['blocked'])->toStartWith('question: ');
});

it('files what the planner discovered and records its provisional decision; the plan still covers the card', function () {
    @mkdir($this->wt.'/.tmp', 0775, true);
    file_put_contents($this->wt.'/.tmp/question.md', PLAN_PROVISIONAL."\n");

    $staged = stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]),
        ['--question-file=.tmp/question.md', '--discovered=bug: Totals round wrong — the footer sums floats', '--note=The PDF layout exists']);
    plannerStop($this->p, $this->wt);

    $card = $this->p->card($this->id);
    $planned = logOf($card, 'planned')[0];
    $found = $this->p->card($planned['discovered'][0]);
    expect($staged->getOutput())->toStartWith("staged plan for {$this->id}: 9 lines, 1 file, 1 discovered, 1 question\n")
        ->and($card['body'])->toContain('## Provisional decision ('.gmdate('Y-m-d').")\nWhich date an export file names")
        ->and($planned)->toMatchArray(['note' => 'The PDF layout exists'])
        ->and($found)->toMatchArray(['stage' => 'backlog', 'type' => 'bug', 'title' => 'Totals round wrong'])
        ->and($found['labels'])->toContain('discovered')
        ->and($found['body'])->toStartWith("Discovered by {$this->id} (Invoice export) while planning it.")
        ->and($this->p->sandbox->ok(['stop', $this->id, '--to=ready']))->toContain("{$this->id} planning→ready");
});

it('points the worker at the plan on its card, and names what changed since the plan', function () {
    [$id, $wt] = $this->p->started('Export notes');
    $base = substr(trim($this->p->git($this->p->main, 'rev-parse', 'main')), 0, 7);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'w1']));
    $this->p->enter($wt, 'w1');

    $context = $this->p->in($wt, ['context'])->getOutput();
    expect($context)->toContain("plan: `vendor/bin/kanban show {$id} --plan` (made @{$base}): read it whole first and follow its steps")
        ->and($context)->not->toContain('changed since the plan')
        ->and($this->p->in($wt, ['show', $id, '--plan'])->getOutput())->toBe(Sandbox::planFor([1, 2]))
        ->and(file_exists($wt.'/.tmp/plan.md'))->toBeFalse();

    $this->p->commit($this->p->main, 'README.md', "# app, changed\n", 'main moves');
    $this->p->sandbox->ok(['set', $id, 'accept[1]=It renders the export as a PDF', '--reason=the owner wants a PDF']);
    $context = $this->p->in($wt, ['context'])->getOutput();

    expect($context)->toContain('the card changed since the plan (a reworded criterion, the body): where they differ, the card holds')
        ->and($context)->toContain('main changed since the plan, in files it names: README.md: check what the plan says about them against the code');

    $unplanned = $this->p->sandbox->kanban(['show', $this->p->sandbox->card('Not planned yet'), '--plan']);
    expect($unplanned->getExitCode())->toBe(4)
        ->and($unplanned->getErrorOutput())->toContain('has no plan');
});

it('plans a half-done card on its parked branch as it is, and keeps the branch where planning found it', function () {
    [$id, $wt] = $this->p->started('Seat limits');
    $head = $this->p->commit($wt, 'seats.php', "<?php\n", "{$id}: half");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'w1']));
    $this->p->enter($wt, 'w1');
    $this->p->in($wt, ['report', $id, '--status=blocked', '--reason=question: one seat limit per team?'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $wt, 'agent' => 'w1']))->mustRun();
    $this->p->sandbox->ok(['stop', $id, '--to=backlog']);
    $this->p->commit($this->p->main, 'main.txt', "moved\n", 'main moves');
    expect($this->p->sandbox->ok(['answer', $id, '--note=One per team.']))->toContain("promoted {$id} to planning: its parked branch card/");

    $out = $this->p->sandbox->ok(['start', $id]);
    preg_match('/^worktree (.+)$/m', $out, $m);
    $planner = realpath($m[1]);
    $card = $this->p->card($id);
    $branch = $card['work']['branch'];
    expect($this->p->sandbox->ok(['refresh', $id]))->toStartWith("up to date {$id}: its planner reads the parked branch as it is\n");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'p2', 'type' => 'kanban-planner']));
    $this->p->enter($planner, 'p2', 'kanban-planner');
    $context = $this->p->in($planner, ['context'])->getOutput();
    expect($out)->toContain("started planning {$id}\n")->toContain("branch {$branch} (parked branch reused)\n")->not->toContain('merged main')
        ->and($card['work'])->toMatchArray(['parked_branch' => $branch, 'head' => $head])
        ->and(trim($this->p->git($planner, 'rev-parse', 'HEAD')))->toBe($head)
        ->and(file_get_contents($planner.'/.tmp/plan.md'))->toBe(Sandbox::planFor([1, 2]))
        ->and($context)->toContain('earlier plan in .tmp/plan.md (made @')
        ->and($context)->toContain("parked branch {$branch}: the work done so far (`git log refs/heads/main..HEAD`, `git diff refs/heads/main...HEAD`); plan what is left. The worker's start merges main into it; main changed since: main.txt");

    // what main added since the park is the worker's too: its start merges main in
    stagePlan($this->p, $planner, $id, Sandbox::planFor([1, 2], 'main.txt'))->mustRun();
    expect(stagePlan($this->p, $planner, $id, str_replace('- read `main.txt`', '- create `main.txt`', Sandbox::planFor([1, 2], 'main.txt')))->getErrorOutput())
        ->toContain('## Files: `main.txt` already exists');

    $this->p->commit($planner, 'stray.txt', "a planner commits nothing\n", 'stray');
    stagePlan($this->p, $planner, $id, Sandbox::planFor([1, 2], 'seats.php'))->mustRun();
    plannerStop($this->p, $planner, 'p2');
    $out = $this->p->sandbox->ok(['stop', $id, '--to=ready']);

    expect($out)->toContain("reset branch {$branch} to ".substr($head, 0, 7).": planning changes no commit\n")
        ->and($out)->toContain("parked branch {$branch} kept\n")
        ->and($this->p->card($id))->toMatchArray(['stage' => 'ready', 'work' => ['parked_branch' => $branch]])
        ->and(trim($this->p->git($this->p->main, 'rev-parse', $branch)))->toBe($head)
        ->and($this->p->sandbox->ok(['start', $id]))->toContain("branch {$branch} (parked branch reused)\nmerged main into the parked branch (".substr($head, 0, 7).'..');
});

it('counts only a plan made under the claim the planner holds now', function () {
    $id = $this->p->sandbox->readyCard('Old plan');
    $this->p->sandbox->ok(['set', $id, 'body=Build it again']);
    expect($this->p->card($id)['stage'])->toBe('planning');

    $this->p->sandbox->ok(['start', $id]);
    $refused = $this->p->sandbox->kanban(['stop', $id, '--to=ready']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$id} has no plan from its planner yet");
});

it('refuses a plan staged before the owner overruled a decision on the card', function () {
    $this->p->sandbox->ok(['set', $this->id, 'body=@-'], [], "Build it\n\n".PLAN_PROVISIONAL."\n");
    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();

    expect($this->p->sandbox->ok(['answer', $this->id, '2']))->toContain("{$this->id}#1: the agent took 1; the card is planned again for 2");
    $stop = plannerStop($this->p, $this->wt);

    expect($stop['json']['decision'])->toBe('block')
        ->and($stop['json']['reason'])->toContain("{$this->id} changed since the plan was staged");
});

it('plans a card parked on another machine from main, and says so', function () {
    [$id, $wt] = $this->p->started('Seat limits');
    $this->p->commit($wt, 'seats.php', "<?php\n", "{$id}: half");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'w1']));
    $this->p->enter($wt, 'w1');
    $this->p->in($wt, ['report', $id, '--status=blocked', '--reason=question: one seat limit per team?'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $wt, 'agent' => 'w1']))->mustRun();
    $this->p->sandbox->ok(['stop', $id, '--to=backlog']);
    $branch = $this->p->card($id)['work']['parked_branch'];
    // a card branch lives on the machine its worker ran on
    $this->p->git($this->p->main, 'branch', '-q', '-D', $branch);
    $this->p->sandbox->ok(['answer', $id, '--note=One per team.']);

    $out = $this->p->sandbox->ok(['start', $id]);

    expect($out)->toContain("started planning {$id}\n")
        ->and($out)->toContain("branch {$branch} (its parked branch {$branch} is not on this machine: from main)\n")
        ->and($this->p->card($id)['work']['parked_branch'] ?? null)->toBeNull();
});

it('moves a planner\'s fresh branch to main on refresh, but not while its planner runs', function () {
    [$id, $wt] = $this->p->planning('Tag notes');
    $main = $this->p->commit($this->p->main, 'main.txt', "moved\n", 'main moves');

    $out = $this->p->sandbox->ok(['refresh', $id]);
    $live = $this->p->sandbox->kanban(['refresh', $this->id]);

    expect($out)->toContain("refreshed {$id}: its planning branch moved to main (")
        ->and($out)->toContain('spawn: Agent(subagent_type="kanban-planner"')
        ->and(trim($this->p->git($wt, 'rev-parse', 'HEAD')))->toBe($main)
        ->and($live->getExitCode())->toBe(3)
        ->and($live->getErrorOutput())->toContain("{$this->id}: its planner is still running");
});

it('shows a planner what was said since the earlier plan, and applies the same plan staged under a new claim', function () {
    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();
    plannerStop($this->p, $this->wt);
    $this->p->sandbox->ok(['stop', $this->id, '--to=ready']);
    $this->p->sandbox->ok(['set', $this->id, 'note=Reuse the PDF layout, no new class']);
    $this->p->sandbox->ok(['move', $this->id, 'planning', '--reason=plan it with the PDF layout']);

    preg_match('/^worktree (.+)$/m', $this->p->sandbox->ok(['start', $this->id]), $m);
    $wt = realpath($m[1]);
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'p3', 'type' => 'kanban-planner']));
    $this->p->enter($wt, 'p3', 'kanban-planner');
    $context = $this->p->in($wt, ['context'])->getOutput();
    stagePlan($this->p, $wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();

    expect($context)->toContain('Reuse the PDF layout, no new class')
        ->and($context)->toContain('ready→planning: plan it with the PDF layout')
        ->and(plannerStop($this->p, $wt, 'p3')['json'])->toBeNull()
        ->and($this->p->sandbox->ok(['stop', $this->id, '--to=ready']))->toContain("{$this->id} planning→ready");
});

it('clears a block that is not a question when its planner\'s plan moves the card to ready', function () {
    stagePlan($this->p, $this->wt, $this->id, Sandbox::planFor([1, 2]))->mustRun();
    plannerStop($this->p, $this->wt);
    $this->p->sandbox->ok(['set', $this->id, 'blocked=kanban run: docker compose down failed']);

    $this->p->sandbox->ok(['stop', $this->id, '--to=ready']);

    $card = $this->p->card($this->id);
    expect($card)->toMatchArray(['stage' => 'ready', 'blocked' => null])
        ->and(end($card['log']))->toMatchArray(['to' => 'ready', 'via' => 'plan', 'unblocked' => 'kanban run: docker compose down failed']);
});

it('warns the worker when main changed a file under a directory its plan names', function () {
    $this->p->commit($this->p->main, 'app/Models/Note.php', "<?php\n", 'models');
    $id = $this->p->sandbox->card('Note export', ['--body=Build it', '--accept=It exports', '--stage=planning', '--label=area:notes']);
    $this->p->sandbox->ok(['plan', $id, '--plan-file=-'], [], Sandbox::planFor([1], 'app/Models'));
    preg_match('/^worktree (.+)$/m', $this->p->sandbox->ok(['start', $id]), $m);
    $wt = realpath($m[1]);
    $this->p->commit($this->p->main, 'app/Models/Note.php', "<?php // changed\n", 'models change');
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'w2']));
    $this->p->enter($wt, 'w2');

    expect($this->p->in($wt, ['context'])->getOutput())->toContain('main changed since the plan, in files it names: app/Models/Note.php');
});

it('says a reworded criterion sends a card in review back to its worker, not to planning', function () {
    [$id, $wt] = $this->p->started('Invoice totals');
    $this->p->commit($wt, 'totals.php', "<?php\n", "{$id}: totals");
    $this->p->hook('subagent-start', $this->p->payload('subagent-start', ['agent' => 'w3']));
    $this->p->enter($wt, 'w3');
    $this->p->in($wt, ['report', $id, '--status=review', '--tick=1,2', '--summary=Done'])->mustRun();
    $this->p->hook('subagent-stop', $this->p->payload('subagent-stop', ['cwd' => $wt, 'agent' => 'w3']))->mustRun();

    expect($this->p->sandbox->ok(['set', $id, 'accept[1]=It renders the totals', '--reason=the owner wants totals']))->toBe("{$id} updated, review→doing\n");
});
