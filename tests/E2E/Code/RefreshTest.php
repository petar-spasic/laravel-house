<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

/** The compose file with the worktree mount: card agents' shells, and refresh's merge, run in the card's container. */
function containerShell(CodeSandbox $code): void
{
    $code->commitMain('docker-compose.local.yml', "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices:\n  app:\n    volumes: ['./:\${KANBAN_WORKTREE_PATH:-/app}']\n");
}

/** `exec` calls into the card's container, with their working directory. */
function execs(CodeSandbox $code): array
{
    return array_values(array_filter($code->calls(), fn (string $call) => str_starts_with($call, 'exec ')));
}

it('merges main into a card in doing and logs the round', function () {
    $code = $this->code;
    $id = $code->started('Refresh me');
    $before = $code->commit($id, 'feature.txt', "feature\n");
    $code->commitMain('other.txt', "other\n");

    $output = $code->ok(['refresh', $id]);

    $card = $code->sandbox->read($id);
    $after = trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD'));
    expect($output)->toMatch("/^refreshed {$id}: merged main \\(\\w{7}\\.\\.\\w{7}\\)\nspawn: Agent\\(subagent_type=\"kanban-worker\", .+\\)\n$/")
        ->and($card['stage'])->toBe('doing')
        ->and(array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'refresh')))->sequence(
            fn ($e) => $e->toMatchArray(['by' => 'owner', 'from' => $before, 'head' => $after]),
        )
        ->and(is_file($code->worktree($id).'/other.txt'))->toBeTrue()
        ->and(trim($code->sandbox->git('rev-parse', $card['work']['branch'])))->toBe($after)
        ->and($code->ok(['refresh', $id]))->toStartWith("up to date {$id}\nspawn: Agent(subagent_type=\"kanban-worker\"")
        ->and($code->sandbox->read($id)['log'])->toHaveCount(count($card['log']));
});

it('refuses a card in review, which the merge queue merges as it is, and --all leaves it alone', function () {
    $code = $this->code;
    $id = $code->started('Approved');
    $code->commit($id, 'feature.txt', "feature\n");
    $code->ok(['move', $id, 'review', '--force'], ['KANBAN_SESSION' => 's1']);
    $code->commitMain('other.txt', "other\n");
    $head = trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD'));

    $refused = $code->kanban(['refresh', $id]);
    $all = $code->kanban(['refresh', '--all']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$id} is in review: a card in review is judged and merged as it is; the merge queue merges main")
        ->and($all->getExitCode())->toBe(0)
        ->and($all->getOutput())->toBe('')
        ->and(trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD')))->toBe($head);
});

it('says when a refresh brings migrations from main', function () {
    $code = $this->code;
    $id = $code->started('Tagged notes');
    $code->commit($id, 'feature.txt', "feature\n");
    @mkdir($code->root().'/database/migrations', 0775, true);
    $code->commitMain('database/migrations/2026_01_10_093015_create_notes_table.php', "<?php\n");

    expect($code->ok(['refresh', $id]))->toContain("1 migration(s) arrived from main: the card's agent runs the `database` commands `kanban context` prints\n");
});

it('reloads the card stack when a refresh brings changed docker files or lockfiles', function () {
    $code = $this->code;
    $id = $code->started('Caddy moved');
    $code->commit($id, 'feature.txt', "feature\n");
    $project = $code->sandbox->read($id)['work']['stack']['project'];
    @mkdir($code->root().'/docker', 0775, true);
    @mkdir($code->root().'/frontend', 0775, true);
    file_put_contents($code->root().'/frontend/package-lock.json', "{}\n");
    $code->sandbox->git('add', 'frontend/package-lock.json');
    $code->commitMain('docker/Caddyfile.local', ":8080 {\n}\n");
    $recreates = fn () => count(array_filter($code->calls(), fn ($call) => str_contains($call, 'up -d --build --force-recreate')));

    expect($code->ok(['refresh', $id]))->toContain("reloaded {$project}: docker/Caddyfile.local, frontend/package-lock.json changed\n")
        ->and($recreates())->toBe(1);

    $code->commitMain('other.txt', "other\n");
    expect($code->ok(['refresh', $id]))->not->toContain('reloaded')
        ->and($recreates())->toBe(1);
});

it('refuses to refresh a card whose agent is still running, and skips it under --all', function () {
    $code = $this->code;
    $id = $code->started('Busy');
    $code->commit($id, 'feature.txt', "feature\n");
    $code->commitMain('other.txt', "other\n");
    $agent = $code->root().'/.git/laravel-house/agents/a4d2c0ffee.json';
    @mkdir(dirname($agent), 0775, true);
    file_put_contents($agent, json_encode(['agent_id' => 'a4d2c0ffee', 'agent_type' => 'kanban-worker', 'card' => $id, 'stopped_at' => null]));
    $head = trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD'));

    $refused = $code->kanban(['refresh', $id]);
    $all = $code->kanban(['refresh', '--all']);

    expect($refused->getExitCode())->toBe(3)
        ->and($refused->getErrorOutput())->toContain("{$id}: its worker is still running (what it stages applies when it stops); `vendor/bin/kanban wait {$id}`")
        ->and($all->getExitCode())->toBe(0)
        ->and($all->getOutput())->toBe("skipped {$id}: worker live\n")
        ->and(trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD')))->toBe($head);

    file_put_contents($agent, json_encode(['agent_id' => 'a4d2c0ffee', 'agent_type' => 'kanban-worker', 'card' => $id, 'stopped_at' => '2026-01-01T00:00:00.000+00:00']));
    expect($code->ok(['refresh', $id]))->toStartWith("refreshed {$id}");
});

it('leaves a conflicting merge in progress, made in the card\'s container, and drops the report staged before it', function () {
    $code = $this->code;
    containerShell($code);
    $id = $code->started('Conflict');
    $wt = $code->worktree($id);
    $before = $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    $staged = $code->root().'/.git/laravel-house/staged/'.$id.'.report.json';
    @mkdir(dirname($staged), 0775, true);
    file_put_contents($staged, json_encode(['card' => $id, 'status' => 'review', 'hash' => 'abc', 'staged_at' => '2026-01-01T00:00:00.000+00:00']));
    $container = 'acme-wt-'.basename($wt).'-app-1';
    $execs = count(execs($code));

    $run = $code->kanban(['refresh', $id]);

    $project = $code->sandbox->read($id)['work']['stack']['project'];
    expect($run->getExitCode())->toBe(5)
        ->and($run->getOutput())->toContain("discarded the report staged for {$id} before the merge\n"
            ."conflict {$id}: merge of main left in progress in {$wt}\nconflicted app.php\n"
            ."stack {$project} serves the conflicted tree until the worker concludes the merge; run no checks against it\n"
            ."SendMessage: Card {$id}: main moved; a merge of main into your branch is in progress in your worktree, with conflicts in app.php. ")
        ->and($staged)->not->toBeFile()
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and(array_values(array_filter($code->sandbox->read($id)['log'], fn ($e) => $e['event'] === 'refresh'))[0])->toMatchArray(['from' => $before, 'conflicts' => ['app.php']])
        ->and(trim($code->gitIn($wt, 'rev-parse', '-q', '--verify', 'MERGE_HEAD')))->not->toBe('')
        ->and(array_slice(execs($code), $execs))->each->toContain(" -w {$wt} -e KANBAN_CMD {$container} bash -c ")
        ->and(array_slice(execs($code), $execs))->not->toBe([])
        ->and($code->ok(['status']))->toMatch("/^doing  {$id} .*, merge in progress$/m");
});

it('brings a card stack that went down back up for the merge', function () {
    $code = $this->code;
    containerShell($code);
    $id = $code->started('Stack down');
    $code->commit($id, 'feature.txt', "feature\n");
    $code->commitMain('other.txt', "other\n");
    $project = $code->sandbox->read($id)['work']['stack']['project'];
    $state = json_decode(file_get_contents($code->docker.'/state.json'), true);
    unset($state['projects'][$project]);
    file_put_contents($code->docker.'/state.json', json_encode($state));

    expect($code->ok(['refresh', $id]))->toStartWith("refreshed {$id}")
        ->and(is_file($code->worktree($id).'/other.txt'))->toBeTrue()
        ->and(json_decode(file_get_contents($code->docker.'/state.json'), true)['projects'])->toHaveKey($project);
});
