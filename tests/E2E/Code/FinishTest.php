<?php

use PetarSpasic\Kanban\Tests\Support\CodeSandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
});

it('merges an approved card, marks it done and tears down its stack, worktree and branch', function () {
    $code = $this->code;
    $code->configure(['finish' => ['after' => ['echo migrated > after.txt', 'php artisan db:seed --class=ReferenceDataSeeder --force']]]);
    $id = $code->started('Add login page');
    $lc = strtolower($id);
    $wt = $code->worktree($id);
    $code->commit($id, 'login.php', "<?php\n", 'Login page');
    $code->approve($id);
    $branch = "card/{$lc}-add-login-page";

    $output = $code->ok(['finish', $id]);

    $sha = trim($code->sandbox->git('rev-parse', 'main'));
    expect($output)->toBe(implode("\n", [
        "merged {$id} into main ".substr($sha, 0, 7),
        "{$id} review→done",
        'after: echo migrated > after.txt ok',
        "stack down acme-wt-{$lc}; slot released",
        "removed worktree .claude/worktrees/{$lc}",
        "deleted branch {$branch}",
    ])."\n")
        ->and(trim($code->sandbox->git('log', '-1', '--format=%s%n%P', 'main')))->toMatch("/^{$id}: Add login page\n\\S+ \\S+$/")
        ->and(is_file($code->root().'/login.php'))->toBeTrue()
        ->and(trim(file_get_contents($code->root().'/after.txt')))->toBe('migrated')
        ->and(is_dir($wt))->toBeFalse()
        ->and(trim($code->sandbox->git('branch', '--list', $branch)))->toBe('')
        ->and(trim($code->sandbox->git('worktree', 'list')))->not->toContain($lc)
        ->and($code->stacks())->toBe([])
        ->and($code->calls())->toContain("compose --project-directory {$wt} -f {$wt}/docker-compose.local.yml -p acme-wt-{$lc} down -v --remove-orphans --rmi local -t 5");

    $card = $code->sandbox->read($id);
    expect($card['stage'])->toBe('done')
        ->and($card['claim'])->toBeNull()
        ->and(array_keys($card['work']))->toBe(['branch', 'base', 'merge', 'started', 'finished'])
        ->and($card['work']['merge'])->toBe($sha);
});

it('prints a rebuild hint when the merge touches lockfiles or docker files', function () {
    $code = $this->code;
    $id = $code->started('Bump deps');
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);

    expect($code->ok(['finish', $id]))->toContain('rebuild main: composer.lock changed; run `docker compose -f docker-compose.local.yml up -d --build`');
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
        @mkdir($c->root().'/.git/laravel-kanban/agents', 0775, true);
        file_put_contents($c->root().'/.git/laravel-kanban/agents/a1.json', json_encode([
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
    'uncommitted main change to a branch file' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        file_put_contents($c->root().'/app.php', "<?php\n\nreturn 'local';\n");
    }, 3, 'uncommitted changes to files the branch changes', 'review'],
    'main checkout on another branch' => [function (CodeSandbox $c, string $id) {
        $c->approve($id);
        $c->sandbox->git('checkout', '-q', '-b', 'other');
    }, 3, "on 'other', not main", 'review'],
]);

it('merges main into the branch on refresh and clears the approval', function () {
    $code = $this->code;
    $id = $code->started('Refresh me');
    $code->commit($id, 'feature.txt', "feature\n");
    $code->approve($id);
    $code->commitMain('other.txt', "other\n");

    $output = $code->ok(['refresh', $id]);

    $card = $code->sandbox->read($id);
    expect($output)->toMatch("/^refreshed {$id}: merged main \\(\\w{7}\\.\\.\\w{7}\\); re-verify before finish\n$/")
        ->and($card['stage'])->toBe('review')
        ->and($card['work']['approved'])->toBeNull()
        ->and(is_file($code->worktree($id).'/other.txt'))->toBeTrue()
        ->and($code->ok(['refresh', $id]))->toBe("up to date {$id}\n");
});

it('leaves a conflicting refresh in progress and sends the card back to doing', function () {
    $code = $this->code;
    $id = $code->started('Conflict');
    $code->commit($id, 'app.php', "<?php\n\nreturn 'branch';\n");
    $code->approve($id);
    $code->commitMain('app.php', "<?php\n\nreturn 'main';\n");

    $run = $code->kanban(['refresh', $id]);

    $wt = $code->worktree($id);
    expect($run->getExitCode())->toBe(5)
        ->and($run->getOutput())->toContain("conflict {$id}: merge of main left in progress in {$wt}\nconflicted app.php\n{$id} → doing\nSendMessage: Card {$id}: main moved")
        ->and($run->getOutput())->toContain("vendor/bin/kanban report {$id} --status=review")
        ->and($code->sandbox->read($id)['stage'])->toBe('doing')
        ->and($code->sandbox->read($id)['work']['approved'])->toBeNull()
        ->and(trim($code->gitIn($wt, 'rev-parse', '-q', '--verify', 'MERGE_HEAD')))->not->toBe('');
});
