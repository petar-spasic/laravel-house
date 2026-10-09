<?php

use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->code = CodeSandbox::create(['gates' => ['report' => []]]);
    $this->code->defaults = ['FAKE_DOCKER_SERVE' => '1'];
    $this->claude = Sandbox::tmp();
    runAgent($this->claude, 'worker', <<<'SH'
        cd "$WORKTREE" && echo "$RANDOM" >> feature.txt && git add -A && git commit -qm "$CARD: feature" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" approve --check=1:pass:"feature.txt holds it"
        SH);
});

/** An approved card of $code whose branch changed $file. */
function queued(CodeSandbox $code, string $title, string $file, string $content, ?string $at = null): string
{
    $id = $code->started($title);
    $code->commit($id, $file, $content, "{$title}: work");
    $code->approve($id, at: $at);

    return $id;
}

/** The merger's script: a conflict in app.php resolved and committed, or $result staged with $note as it is. */
function merger(string $claude, string $result, string $note = 'Kept the card\'s change and main\'s', string $before = ''): void
{
    $resolve = $result === 'resolved' ? 'cd "$WORKTREE" && printf "<?php\n\nreturn \'card and main\';\n" > app.php && git add app.php && git commit -q --no-edit && cd - > /dev/null' : '';
    runAgent($claude, 'merger', implode("\n", array_filter([$before, $resolve, 'vendor/bin/kanban --in="$WORKTREE" merged "$CARD" '.$result.' --note='.escapeshellarg($note)])));
}

/** @return list<string> the results of the card's `merge` log entries */
function mergeResults(CodeSandbox $code, string $id): array
{
    return array_values(array_map(fn (array $e) => $e['result'], array_filter($code->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'merge')));
}

/** @return list<string> the kanban agents the fake claude was launched as */
function agentsLaunched(string $claude): array
{
    return array_map(fn (array $argv) => $argv[2], runLaunches($claude));
}

it('merges an approved card in a run of its own, after an evaluator judged its branch as it is', function () {
    runAgent($this->claude, 'evaluator', <<<'SH'
        [ -e "$WORKTREE/fix.txt" ] && echo merged > "$FAKE_CLAUDE_DIR/main-seen"
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" approve --check=1:pass:"feature.txt holds it"
        SH);
    $id = $this->code->sandbox->readyCard('Add login page');
    runPass($this->code, $this->claude);
    $this->code->commitMain('fix.txt', "fixed\n");
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id)['work']['approved'])->not->toBeNull()
        ->and(is_file($this->claude.'/main-seen'))->toBeFalse();

    $merging = runPass($this->code, $this->claude);
    $merged = runPass($this->code, $this->claude);

    $sha = trim($this->code->sandbox->git('rev-parse', 'main'));
    expect($merging)->toContain(" {$id} merging\n")->not->toContain("merged {$id}")
        ->and($merged)->toContain(" merged {$id} into main ".substr($sha, 0, 7)."\n")->toContain(" {$id} review→done\n")
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'done'])
        ->and($this->code->sandbox->read($id)['work']['merge'])->toBe($sha)
        ->and(is_file($this->code->root().'/fix.txt') && is_file($this->code->root().'/feature.txt'))->toBeTrue()
        ->and(array_filter($this->code->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'refresh'))->toBe([])
        ->and(agentsLaunched($this->claude))->toBe(['kanban-worker', 'kanban-evaluator'])
        ->and($this->code->lease())->toBeNull();
});

it('hands a conflict to a merger in the merge clone, then checks and merges what it resolved', function () {
    $id = queued($this->code, 'Tag notes', 'app.php', "<?php\n\nreturn 'card';\n");
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    merger($this->claude, 'resolved');

    expect(runPass($this->code, $this->claude))->toContain(" {$id} merging\n");
    $launched = runPass($this->code, $this->claude);
    expect($launched)->toContain(" {$id}: conflicts in app.php; the merger's turn, the merge lease stays held\n")
        ->toMatch("/ {$id} merger [0-9a-f]{8} launched \\(opus, medium\\): conflicts in app\\.php\n/")
        ->and($this->code->mergeState())->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1]);
    runPass($this->code, $this->claude);
    $merged = runPass($this->code, $this->claude);

    [$merger] = runLaunches($this->claude);
    expect(array_slice($merger, 0, 7))->toBe(['-p', '--agent', 'kanban-merger', '--model', 'opus', '--effort', 'medium'])
        ->and(end($merger))->toBe("Card {$id}. Worktree ".realpath($this->code->mergeClone()))
        ->and($merged)->toContain("merged {$id} into main")
        ->and(file_get_contents($this->code->root().'/app.php'))->toBe("<?php\n\nreturn 'card and main';\n")
        ->and(mergeResults($this->code, $id))->toBe(['conflict', 'resolved'])
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and($this->code->lease())->toBeNull();
});

it('raises once that the main checkout holds a file git does not track where the card adds one, on a board without a remote', function () {
    $id = queued($this->code, 'Add login page', 'login.php', "<?php\n");
    file_put_contents($this->code->root().'/login.php', "<?php // left over\n");
    $attention = ['--once', '--until-attention'];

    expect(runPass($this->code, $this->claude, $attention))->toContain(" {$id} merging\n");
    $raised = runPass($this->code, $this->claude, $attention);
    $again = runPass($this->code, $this->claude, $attention);
    unlink($this->code->root().'/login.php');
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    expect($raised)->toContain("attention:\n  main checkout not moved: the merge of {$id} adds login.php, which git does not track here: remove or commit it")
        ->and($again)->not->toContain('attention:')->not->toContain('merge waits')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('brings the merge stack up again before it launches the merger, when it went down meanwhile', function () {
    $id = queued($this->code, 'Tag notes', 'app.php', "<?php\n\nreturn 'card';\n");
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    merger($this->claude, 'resolved');
    expect(runPass($this->code, $this->claude))->toContain(" {$id} merging\n");
    // a reboot: the merge stack is gone while the merge waits on its merger
    $file = $this->code->docker.'/state.json';
    $docker = json_decode((string) file_get_contents($file), true);
    $project = 'acme-merge-'.substr(sha1(realpath($this->code->root())), 0, 8);
    posix_kill((int) $docker['projects'][$project]['pid'], SIGTERM);
    unset($docker['projects'][$project]);
    file_put_contents($file, json_encode($docker));

    $up = runPass($this->code, $this->claude);
    $docker = json_decode((string) file_get_contents($file), true);
    $launched = runPass($this->code, $this->claude);

    expect($up)->not->toContain('merger ')
        ->and($docker['projects'])->toHaveKey($project)
        ->and($launched)->toMatch("/ {$id} merger [0-9a-f]{8} launched \\(opus, medium\\): conflicts in app\\.php\n/");
});

it('sends a card its merger cannot merge back to its worker with the merger\'s note, and frees the lease in the same pass', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        if [ -f "$FAKE_CLAUDE_DIR/worked" ]; then
            vendor/bin/kanban --in="$WORKTREE" context "$CARD" > "$FAKE_CLAUDE_DIR/context.txt"
            exit 0
        fi
        touch "$FAKE_CLAUDE_DIR/worked"
        cd "$WORKTREE" && printf "<?php\n\nreturn 'card';\n" > app.php && git add -A && git commit -qm "$CARD: app" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    $note = 'app.php: main returns a header the card drops; keep it or say why not';
    merger($this->claude, 'back', $note);
    $id = $this->code->sandbox->readyCard('Tag notes');
    runPass($this->code, $this->claude);
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id))->toMatchArray(['stage' => 'doing'])
        ->and($this->code->sandbox->read($id)['work']['approved'] ?? null)->toBeNull();

    $back = runPass($this->code, $this->claude);

    $workers = array_values(array_filter(runLaunches($this->claude), fn (array $a) => $a[2] === 'kanban-worker'));
    expect($back)->toMatch("/ {$id} worker [0-9a-f]{8} resumed /")
        ->and($this->code->lease())->toBeNull()
        ->and($this->code->mergeState())->toBeNull()
        ->and(mergeResults($this->code, $id))->toBe(['conflict', 'back'])
        ->and($workers)->toHaveCount(2)
        ->and($workers[1])->toContain('--resume')
        ->and(file_get_contents($this->claude.'/context.txt'))->toContain($note);
});

it('merges main into a card the merge sent back before a fresh worker takes it, leaving the conflict for it', function () {
    runAgent($this->claude, 'worker', <<<'SH'
        if [ -f "$FAKE_CLAUDE_DIR/worked" ]; then
            git -C "$WORKTREE" rev-parse -q --verify MERGE_HEAD > "$FAKE_CLAUDE_DIR/merging.txt"
            exit 0
        fi
        touch "$FAKE_CLAUDE_DIR/worked"
        cd "$WORKTREE" && printf "<?php\n\nreturn 'card';\n" > app.php && git add -A && git commit -qm "$CARD: app" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    merger($this->claude, 'back', 'app.php: main returns a header the card drops; keep it or say why not');
    $id = $this->code->sandbox->readyCard('Tag notes');
    runPass($this->code, $this->claude);
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    foreach (range(1, 3) as $pass) {
        runPass($this->code, $this->claude);
    }
    expect($this->code->sandbox->read($id)['stage'])->toBe('doing');
    // its worker's session is gone: a fresh one takes the card
    array_map('unlink', glob($this->code->root().'/.git/laravel-house/agents/*.json'));

    $fresh = runPass($this->code, $this->claude);

    $refreshed = array_values(array_filter($this->code->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'refresh'));
    expect($fresh)->toMatch("/ {$id} worker [0-9a-f]{8} launched /")
        ->and($refreshed)->toHaveCount(1)
        ->and($refreshed[0]['conflicts'] ?? null)->toBe(['app.php'])
        ->and(trim((string) @file_get_contents($this->claude.'/merging.txt')))->toBe(trim($this->code->sandbox->git('rev-parse', 'main')));
});

it('lets the merger fix what the merge turned red, and merges the fix', function () {
    $id = queued($this->code, 'Tag notes', 'RED', "the suite fails\n");
    merger($this->claude, 'fixed', 'The card left a RED file behind', 'cd "$WORKTREE" && git rm -q RED && git commit -qm "Drop RED" && cd - > /dev/null');

    runPass($this->code, $this->claude);
    expect(runPass($this->code, $this->claude))->toMatch("/ {$id} merger [0-9a-f]{8} launched \\(opus, medium\\): red: `test ! -e RED`\n/");
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and(mergeResults($this->code, $id))->toBe(['red', 'fixed'])
        ->and(file_exists($this->code->root().'/RED'))->toBeFalse()
        ->and($this->code->sandbox->git('log', '-1', '--format=%s', 'main'))->toBe("Drop RED\n");
});

it('holds the queue on a main the merger finds red, tells the main session once, and merges again once main is fixed and published', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['install' => ['composer.lock' => 'true']]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'install');
    $this->code->sandbox->addRemote(Origin::create());
    $id = queued($this->code, 'Tag notes', 'composer.lock', "{}\n", '2026-01-01T00:00:00.000+00:00');
    $later = queued($this->code, 'Archive notes', 'archive.php', "<?php\n", '2026-01-02T00:00:00.000+00:00');
    $red = substr($this->code->commitMain('RED', "main fails\n"), 0, 7);
    $this->code->sandbox->git('push', '-q', 'origin', 'main');
    merger($this->claude, 'main', 'test ! -e RED — RED is on main');
    $log = fn () => (string) @file_get_contents($this->code->root().'/.git/laravel-house/run.log');
    $line = "main is red: `test ! -e RED` fails on main alone at {$red}, found merging {$id}: fix it on main and `vendor/bin/kanban publish`; the merge queue holds until main moves (`vendor/bin/kanban show {$id} --log=5` has its output)";

    runPass($this->code, $this->claude);
    expect($this->code->mergeState()['failure'])->toMatchArray(['command' => 'test ! -e RED', 'base_rerun' => 'skipped']);
    runPass($this->code, $this->claude);
    $held = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $again = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    $cards = collect(glob($this->code->root().'/docs/kanban/work/*.json'))->map(fn ($f) => json_decode(file_get_contents($f), true))->filter(fn (array $c) => isset($c['stage']));
    expect($cards->pluck('id')->sort()->values()->all())->toBe(collect([$id, $later])->sort()->values()->all())
        ->and(mergeResults($this->code, $id))->toBe(['red', 'main'])
        ->and(collect($this->code->sandbox->read($id)['log'])->last(fn (array $e) => $e['event'] === 'merge'))->not->toHaveKey('red')
        ->and($held)->toContain("attention:\n  {$line}\n")
        ->and(substr_count($log(), 'main is red'))->toBe(1)
        ->and($again)->toContain('idle: 2 approved cards wait for the merge queue')->not->toContain('main is red')
        ->not->toContain('waits for')->not->toContain('merging')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review')
        ->and($this->code->sandbox->read($later)['stage'])->toBe('review')
        ->and($this->code->lease())->toBeNull();

    $this->code->sandbox->git('rm', '-q', 'RED');
    $this->code->sandbox->git('commit', '-q', '-m', 'Fix main');
    $this->code->ok(['publish']);
    for ($pass = 0; $pass < 8 && $this->code->sandbox->read($later)['stage'] !== 'done'; $pass++) {
        runPass($this->code, $this->claude);
    }

    expect($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and($this->code->sandbox->read($later)['stage'])->toBe('done')
        ->and(substr_count($log(), 'main is red'))->toBe(1);
});

it('sends a card back after three merger rounds that leave it red', function () {
    $id = queued($this->code, 'Tag notes', 'RED', "the suite fails\n");
    merger($this->claude, 'fixed', 'Tried', 'cd "$WORKTREE" && echo "$RANDOM" >> notes.txt && git add notes.txt && git commit -qm "Try" && cd - > /dev/null');

    for ($pass = 0; $pass < 10 && (array_slice(mergeResults($this->code, $id), -1)[0] ?? null) !== 'back'; $pass++) {
        runPass($this->code, $this->claude);
    }

    $back = array_values(array_filter($this->code->sandbox->read($id)['log'], fn (array $e) => ($e['result'] ?? null) === 'back'));
    expect(mergeResults($this->code, $id))->toBe(['red', 'fixed', 'red', 'fixed', 'red', 'fixed', 'red', 'back'])
        ->and($back[0]['note'])->toStartWith("the merge stays red after 3 merger rounds: `test ! -e RED`\n")
        ->and(array_count_values(agentsLaunched($this->claude))['kanban-merger'])->toBe(3)
        ->and($this->code->lease())->toBeNull();
});

it('blocks a card the merge sent back three times since its start', function () {
    $id = $this->code->started('Tag notes');
    appendLog($this->code, $id, array_map(fn (int $n) => ['id' => "MB00000{$n}", 'by' => 'merger', 'event' => 'merge', 'result' => 'back', 'note' => "app.php: unclear, round {$n}"], [0, 1, 2]));

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    $why = 'kanban run: sent back by the merge 3×: app.php: unclear, round 2';
    expect($this->code->sandbox->read($id)['blocked'])->toBe($why)
        ->and($out)->toContain("attention:\n  {$id} blocked: {$why}\n")
        ->and(runLaunches($this->claude))->toBe([]);
});

/** Appends $entries to the card's log, committed on the board. */
function appendLog(CodeSandbox $code, string $id, array $entries): void
{
    $file = glob($code->root()."/docs/kanban/*/{$id}.json")[0];
    $card = json_decode((string) file_get_contents($file), true);
    foreach ($entries as $entry) {
        $card['log'][] = ['at' => $card['updated']] + $entry;
    }
    Json::write($file, Json::encode($card, 'card'));
    $code->sandbox->boardGit('commit', '-q', '-am', "{$id} log (test)");
}

/** Merges $values into this checkout's run.json. */
function setRunJson(CodeSandbox $code, array $values): void
{
    $file = $code->root().'/.git/laravel-house/run.json';
    file_put_contents($file, json_encode($values + (json_decode((string) @file_get_contents($file), true) ?: [])));
}

it('blocks a card once for each loop, the merge\'s and its evaluator\'s, and lets it go on once both were unblocked', function () {
    $id = $this->code->started('Tag notes');
    appendLog($this->code, $id, [
        ...array_map(fn (int $n) => ['id' => "MB00000{$n}", 'by' => 'merger', 'event' => 'merge', 'result' => 'back', 'note' => "app.php: unclear, round {$n}"], [1, 2, 3]),
        ...array_map(fn (int $n) => ['id' => "VR00000{$n}", 'by' => 'evaluator', 'event' => 'verdict', 'decision' => 'reject', 'hash' => "h{$n}", 'failed' => ['1: no index']], [1, 2]),
    ]);

    runPass($this->code, $this->claude);
    $merge = $this->code->sandbox->read($id)['blocked'];
    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    runPass($this->code, $this->claude);
    $reject = $this->code->sandbox->read($id)['blocked'];
    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    runPass($this->code, $this->claude);

    expect($merge)->toBe('kanban run: sent back by the merge 3×: app.php: unclear, round 3')
        ->and($reject)->toBe('kanban run: rejected 2× on criterion 1: no index')
        ->and($this->code->sandbox->read($id)['blocked'])->toBeNull()
        ->and(agentsLaunched($this->claude))->toBe(['kanban-worker']);
});

it('raises a send-back by the merge once, also when its card was blocked and unblocked since', function () {
    runAgent($this->claude, 'worker', 'true');
    $id = $this->code->started('Tag notes');
    appendLog($this->code, $id, [['id' => 'MB000001', 'by' => 'merger', 'event' => 'merge', 'result' => 'back', 'note' => 'app.php: unclear']]);

    $first = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $this->code->sandbox->ok(['set', $id, 'blocked=Waiting on the owner']);
    runPass($this->code, $this->claude);
    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    $again = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($first)->toContain("  {$id} sent back by the merge: app.php: unclear\n")
        ->and($again)->not->toContain('sent back by the merge');
});

it('blocks a card whose merger stops without a result, and lets the lease go', function () {
    runAgent($this->claude, 'merger', 'true');
    $id = queued($this->code, 'Tag notes', 'RED', "the suite fails\n");

    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $aborted = runPass($this->code, $this->claude);

    expect($this->code->sandbox->read($id)['blocked'])->toBe('merger stopped without a result')
        ->and($aborted)->toContain(" merge of {$id} aborted: it is blocked: merger stopped without a result\n")
        ->and(agentsLaunched($this->claude))->toBe(['kanban-merger'])
        ->and($this->code->lease())->toBeNull()
        ->and($this->code->mergeState())->toBeNull();
});

it('blocks a card whose merger dies three times without a stop, and lets the lease go', function () {
    file_put_contents("{$this->claude}/kanban-merger.error", json_encode(['subtype' => 'error_during_execution']));
    $id = queued($this->code, 'Tag notes', 'RED', "the suite fails\n");

    foreach (range(1, 5) as $pass) {
        runPass($this->code, $this->claude);
    }

    expect($this->code->sandbox->read($id)['blocked'])->toBe('merger stopped without a result 3×')
        ->and(agentsLaunched($this->claude))->toBe(['kanban-merger', 'kanban-merger', 'kanban-merger'])
        ->and($this->code->lease())->toBeNull();
});

it('aborts the merge of a card edited while its merger is due, and launches it no merger', function () {
    file_put_contents("{$this->claude}/kanban-merger.error", json_encode(['subtype' => 'error_during_execution']));
    $id = queued($this->code, 'Tag notes', 'RED', "the suite fails\n");
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $this->code->sandbox->ok(['set', $id, 'accept[1]=It works on a phone', '--reason=phones first']);

    $out = runPass($this->code, $this->claude);

    expect($out)->toContain(" merge of {$id} aborted: it is doing\n")
        ->and(array_count_values(agentsLaunched($this->claude))['kanban-merger'])->toBe(1)
        ->and($this->code->lease())->toBeNull()
        ->and($this->code->mergeState())->toBeNull();
});

it('merges a card again from the start once a usage limit that cut its merger short has passed', function () {
    file_put_contents("{$this->claude}/kanban-merger.error", json_encode(['api_error_status' => 429, 'result' => "You've hit your limit"]));
    $id = queued($this->code, 'Tag notes', 'app.php', "<?php\n\nreturn 'card';\n");
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);

    $paused = runPass($this->code, $this->claude);
    expect($paused)->toContain('paused until')->toContain(" merge of {$id} aborted: paused: usage limit\n")
        ->and($this->code->lease())->toBeNull()
        ->and(runPass($this->code, $this->claude))->not->toContain("{$id} merging");

    unlink("{$this->claude}/kanban-merger.error");
    merger($this->claude, 'resolved');
    $state = json_decode((string) file_get_contents($this->code->root().'/.git/laravel-house/run.json'), true);
    file_put_contents($this->code->root().'/.git/laravel-house/run.json', json_encode(['paused_until' => 0] + $state));
    foreach (range(1, 4) as $pass) {
        runPass($this->code, $this->claude);
    }

    expect($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('leaves a merger at work to finish while a usage limit another agent met pauses the launches, then merges', function () {
    $id = queued($this->code, 'Tag notes', 'app.php', "<?php\n\nreturn 'card';\n");
    $this->code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    merger($this->claude, 'resolved', before: 'touch "$FAKE_CLAUDE_DIR/up"; until [ -e "$FAKE_CLAUDE_DIR/go" ]; do sleep 0.1; done');
    $env = ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude];
    runPass($this->code, $this->claude);
    $this->code->kanban(['run', '--once'], $env);
    for ($until = microtime(true) + 30; ! is_file("{$this->claude}/up") && microtime(true) < $until;) {
        usleep(100_000);
    }
    setRunJson($this->code, ['paused_until' => time() + 900]);

    $paused = $this->code->kanban(['run', '--once'], $env);
    touch("{$this->claude}/go");
    runSettled($this->code);

    expect($paused->getOutput().$paused->getErrorOutput())->not->toContain('aborted')
        ->and($this->code->mergeState())->toMatchArray(['phase' => 'checks', 'merger_rounds' => 1]);
    runPass($this->code, $this->claude);
    expect($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('merges nothing while finish.check names no suite, and says so once; the main checkout still follows the remote\'s main', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => []]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'no suite');
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    $peer = $origin->clone('peer');
    file_put_contents($peer->root.'/tags.php', "<?php\n");
    $peer->git('add', 'tags.php');
    $peer->git('commit', '-q', '-m', 'Tags, merged elsewhere');
    $peer->git('push', '-q', 'origin', 'main');

    $first = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $second = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($first)->toContain("attention:\n  finish.check names no suite: no card merges until it does (config/kanban.php)\n")
        ->and($second)->not->toContain('finish.check')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review')
        ->and(trim($this->code->sandbox->git('rev-parse', 'main')))->toBe(trim($peer->git('rev-parse', 'main')));
});

it('settles a push in flight here while finish.check names no suite', function () {
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    Origin::racingPush($this->code->sandbox, 'read lref lsha rest; git push -q origin "$lsha:refs/heads/main"; kill -9 $(cat "'.$this->code->root().'/.git/laravel-house/merge/finish.pid"); exit 1', ref: 'refs/heads/main');
    try {
        $this->code->kanban(['finish', $id]);
    } catch (ProcessSignaledException) {
    }
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => []]]);

    $out = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($out)->not->toContain('--abort')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done')
        ->and($this->code->lease())->toBeNull();
});

it('raises a merge that fails for want of its suite once, retries it only later, and raises it again once the card was blocked in between', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => ['no-such-suite --ci']]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'suite');
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    $line = "{$id} merge failed: finish.check: `no-such-suite --ci` is not found on this machine (exit 127)";

    runPass($this->code, $this->claude);
    $failed = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $waits = runPass($this->code, $this->claude);
    setRunJson($this->code, ['merge_retry_at' => 0]);
    runPass($this->code, $this->claude);
    $repeated = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $this->code->sandbox->ok(['set', $id, 'blocked=Looking into it']);
    runPass($this->code, $this->claude);
    $this->code->sandbox->ok(['set', $id, 'blocked=']);
    setRunJson($this->code, ['merge_retry_at' => 0]);
    runPass($this->code, $this->claude);
    $again = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($failed)->toContain("attention:\n  {$line}")
        ->and($waits)->not->toContain("{$id} merging")
        ->and($repeated)->not->toContain($line)
        ->and($again)->toContain("  {$line}")
        ->and($this->code->lease())->toBeNull();
});

it('raises the cause of a failed merge, and what the main checkout met before it as a notice of its own', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => ['no-such-suite --ci']]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'suite');
    $this->code->sandbox->addRemote(Origin::create());
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    $this->code->commitMain('local.txt', "not pushed\n");

    runPass($this->code, $this->claude);
    $failed = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($failed)->toContain("  {$id} merge failed: finish.check: `no-such-suite --ci` is not found on this machine (exit 127)")
        ->toMatch("/\n  main checkout not moved to [0-9a-f]{7}: it has commits the remote's main lacks/")
        ->not->toContain('merge failed: main checkout');
});

it('follows the remote\'s main while a failed merge waits, raises a main checkout that cannot follow once, and never holds the queue for it', function () {
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $peer = $origin->clone('peer');
    $push = function (string $file) use ($peer) {
        file_put_contents("{$peer->root}/{$file}", "<?php\n");
        $peer->git('add', $file);
        $peer->git('commit', '-q', '-m', "{$file}, merged elsewhere");
        $peer->git('push', '-q', 'origin', 'main');
    };
    $retry = time() + 300;
    $push('notes.php');
    setRunJson($this->code, ['merge_retry_at' => $retry]);

    runPass($this->code, $this->claude);
    expect(trim($this->code->sandbox->git('rev-parse', 'main')))->toBe(trim($peer->git('rev-parse', 'main')));

    $this->code->commitMain('local.txt', "not pushed\n");
    $stuck = [];
    foreach (['tags.php', 'export.php'] as $file) {
        $push($file);
        setRunJson($this->code, ['merge_follow_at' => 0]);
        runPass($this->code, $this->claude);
        $stuck[] = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    }
    $this->code->gitIn($origin->path, 'update-ref', '-d', 'refs/heads/main');
    setRunJson($this->code, ['merge_follow_at' => 0]);
    runPass($this->code, $this->claude);
    $gone = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($stuck[0])->toMatch("/\n  main checkout not moved to [0-9a-f]{7}: it and the remote's main diverged/")
        ->and($stuck[1])->not->toContain('main checkout not moved')
        ->and($gone)->toContain("  main checkout not brought to the remote's main: the remote has no main\n")
        ->and(json_decode((string) file_get_contents($this->code->root().'/.git/laravel-house/run.json'), true)['merge_retry_at'])->toBe($retry);
});

it('raises a main checkout that cannot follow again once it followed in between, and a suite named nowhere again once one was', function () {
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $peer = $origin->clone('peer');
    $stuck = function (string $file) use ($peer) {
        $this->code->commitMain("local-{$file}", "not pushed\n");
        file_put_contents("{$peer->root}/{$file}", "<?php\n");
        $peer->git('add', $file);
        $peer->git('commit', '-q', '-m', "{$file}, merged elsewhere");
        $peer->git('push', '-q', 'origin', 'main');
        setRunJson($this->code, ['merge_follow_at' => 0]);
        runPass($this->code, $this->claude);

        return runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    };
    $suite = function (array $check) {
        $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => $check]]);
        $this->code->sandbox->git('commit', '-q', '-am', 'suite');

        return runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    };

    $first = $stuck('tags.php');
    $this->code->sandbox->git('reset', '-q', '--hard', 'origin/main');
    setRunJson($this->code, ['merge_follow_at' => 0]);
    runPass($this->code, $this->claude);
    runPass($this->code, $this->claude);
    $again = $stuck('export.php');
    $this->code->sandbox->git('reset', '-q', '--hard', 'origin/main');
    $none = $suite([]);
    $suite(['test ! -e RED']);

    expect($first)->toMatch("/\n  main checkout not moved to [0-9a-f]{7}: it and the remote's main diverged/")
        ->and($again)->toMatch("/\n  main checkout not moved to [0-9a-f]{7}: it and the remote's main diverged/")
        ->and($none)->toContain("  finish.check names no suite: no card merges until it does (config/kanban.php)\n")
        ->and($suite([]))->toContain("  finish.check names no suite: no card merges until it does (config/kanban.php)\n");
});

it('blocks no card when main\'s compose file is gone, and says once that the queue cannot merge', function () {
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    unlink($this->code->root().'/docker-compose.local.yml');

    $first = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $second = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($first)->toContain("attention:\n  the merge queue runs its checks in the merge stack: set stack.compose_file\n")
        ->and($second)->not->toContain('merge stack')
        ->and($this->code->sandbox->read($id))->toMatchArray(['stage' => 'review', 'blocked' => null]);
});

it('merges once finish.check names a suite, in a run that started while it named none', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => []]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'no suite');
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    runPass($this->code, $this->claude);
    $run = new Process([PHP_BINARY, $this->code->root().'/vendor/bin/kanban', 'run', '--until-attention', '--timeout=10'], $this->code->root(), $this->code->env([
        'PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]), null, 60);
    $run->start();
    for ($until = microtime(true) + 20; ! is_file($this->code->root().'/.git/laravel-house/run.pid') && microtime(true) < $until;) {
        usleep(50_000);
    }

    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => ['true']]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'suite');
    $run->wait();
    runSettled($this->code);

    expect($run->getOutput())->toContain(" {$id} merging\n");
});

it('drains while finish.check names no suite: an approved card that cannot merge is not in flight', function () {
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => []]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'no suite');
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention', '--timeout=0']);

    expect($out)->toContain('drained: no card in flight')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('ends the merge kanban run started, and the check it runs, when its card is stopped', function () {
    $check = "{$this->claude}/check.pid";
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => ["echo \$\$ > {$check} && exec sleep 60"]]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'slow suite');
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    $this->code->kanban(['run', '--once'], ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]);
    for ($until = microtime(true) + 30; ! is_file($check) && microtime(true) < $until;) {
        usleep(100_000);
    }
    $finish = (int) file_get_contents($this->code->root().'/.git/laravel-house/merge/finish.pid');
    $sleep = (int) file_get_contents($check);

    $stop = $this->code->kanban(['stop', $id, '--to=backlog']);

    expect($stop->getExitCode())->toBe(0, $stop->getErrorOutput())
        ->and($stop->getOutput())->toContain("merge of {$id} aborted")
        ->and(posix_kill($finish, 0) || posix_kill($sleep, 0))->toBeFalse()
        ->and($this->code->lease())->toBeNull()
        ->and($this->code->mergeState())->toBeNull()
        ->and(is_file($this->code->mergeClone().'/notes.php'))->toBeFalse()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('backlog');
});

it('leaves the merge kanban run started running when it refuses to stop a card whose clone holds uncommitted changes', function () {
    $check = "{$this->claude}/check.pid";
    $this->code->configure(['gates' => ['report' => []], 'finish' => ['check' => ["echo \$\$ > {$check} && exec sleep 60"]]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'slow suite');
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    file_put_contents($this->code->worktree($id).'/notes.php', "<?php // edited\n");
    $this->code->kanban(['run', '--once'], ['PATH' => Sandbox::package().'/tests/Support/FakeClaude:'.Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_CLAUDE_DIR' => $this->claude]);
    for ($until = microtime(true) + 30; ! is_file($check) && microtime(true) < $until;) {
        usleep(100_000);
    }
    $finish = (int) file_get_contents($this->code->root().'/.git/laravel-house/merge/finish.pid');

    $stop = $this->code->kanban(['stop', $id, '--to=backlog']);
    $alive = $finish > 0 && posix_kill($finish, 0);
    // the detached finish's group (the shell MergeRun started leads it), and the check in a session of its own
    $group = (int) (json_decode((string) file_get_contents($this->code->root().'/.git/laravel-house/merge/run.json'), true)['pid'] ?? 0);
    foreach ([-$group, (int) @file_get_contents($check)] as $pid) {
        $pid === 0 || posix_kill($pid, SIGKILL);
    }

    expect($stop->getExitCode())->toBe(3)
        ->and($stop->getErrorOutput())->toContain('has uncommitted changes')
        ->and($alive)->toBeTrue()
        ->and($this->code->sandbox->read($id)['stage'])->toBe('review');
});

it('drains once the merge in flight has ended', function () {
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention']);

    expect($out)->toContain(" {$id} merging\n")->toContain("merged {$id} into main")->toContain('drained: no card in flight')
        ->and($this->code->sandbox->read($id)['stage'])->toBe('done');
});

it('keeps the main checkout on the remote\'s main between merges, at most every two minutes', function () {
    $origin = Origin::create();
    $this->code->sandbox->addRemote($origin);
    $peer = $origin->clone('peer');
    file_put_contents($peer->root.'/notes.php', "<?php\n");
    $peer->git('add', 'notes.php');
    $peer->git('commit', '-q', '-m', 'Notes, merged elsewhere');
    $peer->git('push', '-q', 'origin', 'main');

    runPass($this->code, $this->claude);
    $logged = runPass($this->code, $this->claude);

    $sha = trim($peer->git('rev-parse', 'main'));
    expect(trim($this->code->sandbox->git('rev-parse', 'main')))->toBe($sha)
        ->and($logged)->toContain(' main checkout at '.substr($sha, 0, 7)."\n")
        ->and(is_file($this->code->root().'/.git/laravel-house/merge/run.json'))->toBeFalse();
});

it('merges the cards of two machines in approval order', function () {
    $origin = Origin::create();
    $b = $this->code->twoMachines($origin);
    foreach ([$this->code, $b] as $machine) {
        $machine->defaults += ['FAKE_DOCKER_SERVE' => '1'];
    }
    $a = $this->code;
    $first = queued($a, 'Tag notes', 'tags.php', "<?php\n", '2026-01-01T00:00:00.000+00:00');
    $a->ok(['sync']);
    $second = queued($b, 'Archive notes', 'archive.php', "<?php\n", '2026-01-02T00:00:00.000+00:00');
    $b->ok(['sync']);

    $waits = runPass($b, $this->claude);
    expect($waits)->not->toContain("{$second} merging")
        ->and(runPass($a, $this->claude))->toContain("{$first} merging");
    runPass($b, $this->claude);
    runPass($b, $this->claude);

    expect(array_slice($origin->log('main'), 0, 2))->toBe(["{$second}: Archive notes", "{$first}: Tag notes"]);
});

it('brings what another machine merged into a doing card here: the main checkout follows it, and the worker\'s refresh merges it', function () {
    $origin = Origin::create();
    $a = $this->code;
    $b = $a->twoMachines($origin);
    foreach ([$a, $b] as $machine) {
        $machine->defaults += ['FAKE_DOCKER_SERVE' => '1'];
    }
    runAgent($this->claude, 'worker', <<<'SH'
        [ -f "$FAKE_CLAUDE_DIR/worked" ] && exit 0
        touch "$FAKE_CLAUDE_DIR/worked"
        cd "$WORKTREE" && printf "<?php\n\nreturn 'card';\n" > app.php && git commit -qam "$CARD: app" && cd - > /dev/null
        vendor/bin/kanban --in="$WORKTREE" report "$CARD" --status=review --tick=1 --summary=Done
        SH);
    runAgent($this->claude, 'evaluator', <<<'SH'
        vendor/bin/kanban --in="$WORKTREE" verdict "$CARD" reject --check=1:fail:"app.php drops the header"
        SH);
    $id = $a->sandbox->readyCard('Tag notes');
    runPass($a, $this->claude);
    $other = queued($b, 'Archive notes', 'app.php', "<?php\n\nreturn 'archive';\n");
    $b->ok(['finish', $other]);
    $merged = $b->sandbox->read($other)['work']['merge'];

    // the follow runs beside the evaluator; the worker's resume after the reject merges what it brought
    setRunJson($a, ['merge_follow_at' => 0]);
    runPass($a, $this->claude);
    runPass($a, $this->claude);

    $refreshed = array_values(array_filter($a->sandbox->read($id)['log'], fn (array $e) => $e['event'] === 'refresh'));
    expect(trim($a->sandbox->git('rev-parse', 'main')))->toBe($merged)
        ->and($refreshed)->toHaveCount(1)
        ->and($refreshed[0]['conflicts'] ?? null)->toBe(['app.php'])
        ->and(trim($a->gitIn($a->worktree($id), 'rev-parse', 'MERGE_HEAD')))->toBe($merged);
});

it('passes over another machine\'s card at the front once it stood there for 15 minutes, and does not wait again for the next', function () {
    $origin = Origin::create();
    $b = $this->code->twoMachines($origin);
    foreach ([$this->code, $b] as $machine) {
        $machine->defaults += ['FAKE_DOCKER_SERVE' => '1'];
    }
    $offline = queued($this->code, 'Tag notes', 'tags.php', "<?php\n", '2026-01-01T00:00:00.000+00:00');
    $this->code->ok(['sync']);
    $mine = queued($b, 'Archive notes', 'archive.php', "<?php\n", '2026-01-02T00:00:00.000+00:00');
    $next = queued($b, 'Export notes', 'export.php', "<?php\n", '2026-01-03T00:00:00.000+00:00');
    $b->ok(['sync']);
    expect(runPass($b, $this->claude))->not->toContain('merging');

    $b->seen(1000, [$offline]);
    expect(runPass($b, $this->claude))->toContain("{$mine} merging");
    runPass($b, $this->claude);
    runPass($b, $this->claude);

    expect($b->sandbox->read($mine)['stage'])->toBe('done')
        ->and($b->sandbox->read($next)['stage'])->toBe('done')
        ->and($b->sandbox->read($offline)['stage'])->toBe('review');
});

it('raises a merge lease that another machine stopped beating once, before it expires', function () {
    $id = queued($this->code, 'Tag notes', 'notes.php', "<?php\n");
    $board = $this->code->root().'/docs/kanban/kanban.json';
    $kanban = json_decode((string) file_get_contents($board), true);
    $kanban['merge'] = ['id' => '9f2c41d07a8b3e65', 'card' => $id, 'by' => 'ana@host-a', 'who' => 'Ana',
        'since' => '2026-10-09T08:00:00.000+00:00', 'beat' => '2026-10-09T08:04:00.000+00:00'];
    Json::write($board, Json::encode($kanban, 'kanban'));
    $this->code->sandbox->boardGit('commit', '-q', '-am', 'merge lease (test)');
    $this->code->seen(500);

    $first = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);
    $second = runPass($this->code, $this->claude, ['--until-attention', '--timeout=0']);

    expect($first)->toContain("attention:\n  merge lease held by ana@host-a (Ana) for {$id} since 2026-10-09T08:00:00.000+00:00, no beat for 8 min\n")
        ->not->toContain("{$id} merging")
        ->and($second)->not->toContain('no beat for');
});

it('drains only once the doing cards are done, while a red main holds the merge queue', function () {
    file_put_contents("{$this->claude}/kanban-worker.error", json_encode(['api_error_status' => 429, 'result' => "You've hit your limit"]));
    $found = queued($this->code, 'Archive notes', 'archive.php', "<?php\n");
    $file = glob($this->code->root()."/docs/kanban/*/{$found}.json")[0];
    $card = json_decode((string) file_get_contents($file), true);
    $card['log'][] = ['id' => 'MR000001', 'at' => $card['updated'], 'by' => 'main', 'event' => 'merge', 'result' => 'main',
        'command' => 'test ! -e RED', 'base' => trim($this->code->sandbox->git('rev-parse', 'refs/heads/main'))];
    Json::write($file, Json::encode($card, 'card'));
    $this->code->sandbox->boardGit('commit', '-q', '-am', 'main red (test)');
    $id = $this->code->sandbox->readyCard('Tag notes');
    runPass($this->code, $this->claude);

    $out = runPass($this->code, $this->claude, ['--drain', '--until-attention', '--timeout=0']);

    expect($this->code->sandbox->read($id)['stage'])->toBe('doing')
        ->and($out)->toContain('paused until')->not->toContain('drained');
});
