<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

it('merges an approved card, marks it done and tears down its stack, worktree and branch', function () {
    $code = $this->code;
    $code->configure(['finish' => ['after' => ['echo migrated > after.txt', 'php artisan db:seed --class=ReferenceDataSeeder --force']]]);
    $id = $code->started('Add login page');
    $lc = strtolower($id);
    $name = basename($code->worktree($id));
    $wt = $code->worktree($id);
    $code->commit($id, 'login.php', "<?php\n", 'Login page');
    $code->approve($id);
    $branch = "card/{$lc}-add-login-page";

    $output = $code->ok(['finish', $id]);

    $sha = trim($code->sandbox->git('rev-parse', 'main'));
    expect($output)->toBe(implode("\n", [
        "merged {$id} into main ".substr($sha, 0, 7),
        "{$id} review→done",
        "stack down acme-wt-{$name}; slot released",
        "removed worktree .claude/worktrees/{$name}",
        "deleted branch {$branch}",
        'after: echo migrated > after.txt ok',
    ])."\n")
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s%n%P', 'main')))->toMatch("/^{$id}: Add login page\n\\S+ \\S+$/")
        ->and(is_file($code->root().'/login.php'))->toBeTrue()
        ->and(trim(file_get_contents($code->root().'/after.txt')))->toBe('migrated')
        ->and(is_dir($wt))->toBeFalse()
        ->and(trim($code->sandbox->git('branch', '--list', $branch)))->toBe('')
        ->and(trim($code->sandbox->git('worktree', 'list')))->not->toContain($name)
        ->and($code->stacks())->toBe([])
        ->and($code->calls())->toContain("compose --project-directory {$wt} -f {$wt}/docker-compose.local.yml -p acme-wt-{$name} down -v --remove-orphans --rmi local -t 5");

    $card = $code->sandbox->read($id);
    expect($card['stage'])->toBe('done')
        ->and($card['claim'])->toBeNull()
        ->and(array_keys($card['work']))->toBe(['branch', 'base', 'merge', 'started', 'finished'])
        ->and($card['work']['merge'])->toBe($sha);
});

it('merges the card\'s branch while its clone has another branch checked out', function () {
    $code = $this->code;
    $id = $code->started('Scratch branch');
    $branch = $code->sandbox->read($id)['work']['branch'];
    $head = $code->commit($id, 'kept.php', "<?php\n");
    $code->approve($id);
    $code->gitIn($code->worktree($id), 'checkout', '-q', '-b', 'scratch');

    $code->ok(['finish', $id]);

    expect(trim($code->sandbox->git('rev-parse', 'main^2')))->toBe($head)
        ->and(is_file($code->root().'/kept.php'))->toBeTrue()
        ->and(trim($code->sandbox->git('branch', '--list', 'scratch')))->toBe('')
        ->and($branch)->toStartWith('card/');
});

it('rebuilds main\'s stack when the merge touches lockfiles, docker files or the stack compose file', function () {
    $code = $this->code;
    $code->configure(['finish' => ['install' => []]]);
    $root = realpath($code->root());
    $id = $code->started('Bump deps');
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);

    expect($code->ok(['finish', $id]))->toContain("rebuild main: composer.lock changed; rebuilding\nrebuilt main's stack acme-local\n")
        ->and($code->calls())->toContain("compose --project-directory {$root} -f {$root}/docker-compose.local.yml -p acme-local up -d --build --force-recreate --wait");

    $frontend = $code->started('Bump frontend deps');
    @mkdir($code->worktree($frontend).'/frontend', 0775, true);
    $code->commit($frontend, 'frontend/package-lock.json', "{}\n");
    $code->approve($frontend);

    expect($code->ok(['finish', $frontend], ['FAKE_DOCKER_FAIL' => 'up']))->toContain('rebuild main: frontend/package-lock.json changed; rebuilding')
        ->toContain('warning: rebuild main failed: ')->toContain('; run `docker compose -f docker-compose.local.yml up -d --build --force-recreate --wait`');

    $compose = $code->started('Publish the web port on IPv4 only');
    $code->commit($compose, 'docker-compose.local.yml', file_get_contents($code->sandbox->root.'/docker-compose.local.yml')."# ipv4\n");
    $code->approve($compose);
    $before = count($code->calls());

    expect($code->ok(['finish', $compose, '--no-rebuild']))->toContain('rebuild main: docker-compose.local.yml changed; run `docker compose -f docker-compose.local.yml up -d --build --force-recreate --wait`')
        ->and(collect(array_slice($code->calls(), $before))->contains(fn ($call) => str_contains($call, ' up ')))->toBeFalse();
});

it('refuses to finish', function (Closure $arrange, int $exit, string $message, string $stage) {
    $code = $this->code;
    $id = $code->started('Refused finish');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $arrange($code, $id);

    $run = $code->kanban(['finish', $id]);

    expect($run->getExitCode())->toBe($exit)
        ->and($run->getErrorOutput())->toContain($message)
        ->and($code->sandbox->read($id)['stage'])->toBe($stage)
        ->and(is_dir($code->worktree($id)))->toBeTrue()
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s', 'main')))->not->toContain($id)
        ->and($code->stacks())->toHaveCount(1);
})->with([
    'not in review' => [fn () => null, 3, 'is doing, not review', 'doing'],
    'head moved after approval' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        $c->commit($id, 'more.txt', "more\n");
    }, 3, 'no approval for the branch head', 'review'],
    'dirty worktree' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        file_put_contents($c->worktree($id).'/scratch.txt', "x\n");
    }, 3, 'the worktree has uncommitted changes', 'review'],
    'live agent' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        @mkdir($c->root().'/.git/laravel-house/agents', 0775, true);
        file_put_contents($c->root().'/.git/laravel-house/agents/a1.json', json_encode([
            'agent_id' => 'a1', 'agent_type' => 'kanban-worker', 'card' => $id, 'worktree' => $c->worktree($id),
            'bound_at' => gmdate('Y-m-d\TH:i:s.000+00:00'), 'stopped_at' => null, 'stop_blocks' => 0,
        ]));
    }, 3, 'an agent is still bound to the card', 'review'],
    'main moved over the same files since approval' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        $c->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    }, 5, 'main moved since approval', 'review'],
    'branch does not merge' => [function (CodeSandbox $c, string $id) {
        $c->commitMain('app.php', "<?php\n\nreturn 'main';\n");
        $c->approve($id);
    }, 5, 'does not merge cleanly', 'doing'],
    'leftover conflict marker' => [function (CodeSandbox $c, string $id) {
        $c->commit($id, 'notes.md', "<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> main\n");
        $c->approve($id);
    }, 3, 'the branch holds leftover conflict markers;', 'doing'],
    'uncommitted main change to a branch file' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        file_put_contents($c->root().'/app.php', "<?php\n\nreturn 'local';\n");
    }, 3, 'uncommitted changes to files the branch changes', 'review'],
    'main checkout on another branch' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        $c->sandbox->git('checkout', '-q', '-b', 'other');
    }, 3, "on 'other', not main", 'review'],
]);

it('merges main into the branch on refresh, clears the approval and logs the round', function () {
    $code = $this->code;
    $id = $code->started('Refresh me');
    $before = $code->commit($id, 'feature.txt', "feature\n");
    $code->approve($id);
    $code->commitMain('other.txt', "other\n");

    $output = $code->ok(['refresh', $id]);

    $card = $code->sandbox->read($id);
    $after = trim($code->gitIn($code->worktree($id), 'rev-parse', 'HEAD'));
    expect($output)->toMatch("/^refreshed {$id}: merged main \\(\\w{7}\\.\\.\\w{7}\\); re-verify before finish\nspawn: Agent\\(subagent_type=\"kanban-evaluator\", .+\\)\n$/")
        ->and($card['stage'])->toBe('review')
        ->and($card['work']['approved'])->toBeNull()
        ->and(array_values(array_filter($card['log'], fn ($e) => $e['event'] === 'refresh')))->sequence(
            fn ($e) => $e->toMatchArray(['by' => 'owner', 'from' => $before, 'head' => $after]),
        )
        ->and(is_file($code->worktree($id).'/other.txt'))->toBeTrue()
        ->and($code->ok(['refresh', $id]))->toStartWith("up to date {$id}\nspawn: Agent(subagent_type=\"kanban-evaluator\"")
        ->and($code->sandbox->read($id)['log'])->toHaveCount(count($card['log']));
});

it('logs a refresh of a card in doing that has no approval', function () {
    $code = $this->code;
    $id = $code->started('Still working');
    $code->commit($id, 'feature.txt', "feature\n");
    $code->commitMain('other.txt', "other\n");

    $code->ok(['refresh', $id]);

    expect(array_column($code->sandbox->read($id)['log'], 'event'))->toContain('refresh')
        ->and($code->sandbox->read($id)['stage'])->toBe('doing');
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

it('leaves a conflicting refresh in progress, sends the card back to doing and drops the report staged before it', function () {
    $code = $this->code;
    $id = $code->started('Conflict');
    $before = $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    $code->commitMain('app.php', "<?php\n\nreturn 'main';\n");
    $staged = $code->root().'/.git/laravel-house/staged/'.$id.'.report.json';
    @mkdir(dirname($staged), 0775, true);
    file_put_contents($staged, json_encode(['card' => $id, 'status' => 'review', 'hash' => 'abc', 'staged_at' => '2026-01-01T00:00:00.000+00:00']));

    $run = $code->kanban(['refresh', $id]);

    $wt = $code->worktree($id);
    $project = $code->sandbox->read($id)['work']['stack']['project'];
    expect($run->getExitCode())->toBe(5)
        ->and($run->getOutput())->toContain("discarded the report staged for {$id} before the merge\n"
            ."conflict {$id}: merge of main left in progress in {$wt}\nconflicted app.php\n{$id} → doing\n"
            ."stack {$project} serves the conflicted tree until the worker concludes the merge; run no checks against it\n"
            ."SendMessage: Card {$id}: main moved; a merge of main into your branch is in progress in your worktree, with conflicts in app.php. "
            ."Resolve each conflict by keeping both sides' content and adding nothing neither side had, then `git add` the files and "
            .'`git commit --no-edit` to conclude the merge. After it, run `vendor/bin/kanban stack wait`, the `database` commands `vendor/bin/kanban context` lists, '
            ."`vendor/bin/kanban gates` and the whole test suite, then report with `vendor/bin/kanban report {$id} --status=review`.\n")
        ->and($staged)->not->toBeFile()
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and($code->sandbox->read($id)['work']['approved'])->toBeNull()
        ->and(array_values(array_filter($code->sandbox->read($id)['log'], fn ($e) => $e['event'] === 'refresh'))[0])->toMatchArray(['from' => $before, 'conflicts' => ['app.php']])
        ->and(array_values(array_filter($code->sandbox->read($id)['log'], fn ($e) => $e['event'] === 'stage' && ($e['via'] ?? null) === 'refresh')))->sequence(
            fn ($e) => $e->toMatchArray(['from' => 'review', 'to' => 'doing']),
        )
        ->and(trim($code->gitIn($wt, 'rev-parse', '-q', '--verify', 'MERGE_HEAD')))->not->toBe('')
        ->and($code->ok(['status']))->toMatch("/^doing  {$id} .*, merge in progress$/m");

    $code->gitIn($wt, 'checkout', '--theirs', 'app.php');
    $code->gitIn($wt, 'add', 'app.php');
    $code->gitIn($wt, 'commit', '-q', '--no-edit');
    expect($code->ok(['status']))->not->toContain('merge in progress');
});

it('clears the approval when the card is sent back to doing', function () {
    $code = $this->code;
    $id = $code->started('Sent back');
    $code->commit($id, 'app.php', "<?php\n");
    $code->approve($id);

    $code->ok(['move', $id, 'doing', '--reason=Put the table right after the title']);

    expect($code->sandbox->read($id))->toMatchArray(['stage' => 'doing'])
        ->and($code->sandbox->read($id)['work']['approved'])->toBeNull()
        ->and($code->kanban(['finish', $id])->getExitCode())->toBe(3);
});

it("holds a card that changes kanban's own files until the owner forces it, and names them in context", function () {
    $code = $this->code;
    $id = $code->started('Tune the agents');
    $code->commit($id, '.claude/settings.json', "{}\n", 'settings');
    $code->commit($id, 'app/Tuned.php', "<?php\n", 'code');
    $code->approve($id);

    $context = $code->ok(['context', $id], cwd: $code->worktree($id));
    $held = $code->kanban(['finish', $id]);
    $forced = $code->kanban(['finish', $id, '--force']);

    expect($context)->toContain("changes kanban's own files (finish needs the owner): .claude/settings.json\n")
        ->and($held->getExitCode())->toBe(3)
        ->and($held->getErrorOutput())->toContain("{$id} changes files that steer the agents or git: .claude/settings.json")
        ->and($forced->getExitCode())->toBe(0)
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});
