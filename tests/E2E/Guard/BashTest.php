<?php

use PetarSpasic\Kanban\Tests\Support\GuardSandbox;

$report = <<<'SH'
vendor/bin/kanban report ACME-7K2M9Q --status=review --summary-file=- <<'EOF'
Conditional clauses render; git push is left to the main session.
EOF
SH;

it('applies the other-Bash rules', function (string $actor, string $command, ?string $decision, ?string $reason = null, ?string $cwd = null) {
    $result = GuardSandbox::shared()->case($actor, 'Bash', ['command' => $command], $cwd);

    expect($result['decision'])->toBe($decision, $result['out']);
    if ($reason !== null) {
        expect($result['reason'])->toContain($reason);
    }
})->with([
    'worker shows its card' => ['worker', 'vendor/bin/kanban show ACME-7K2M9Q', 'allow', null, '{wt}'],
    'worker reads status via artisan' => ['worker', 'php artisan kanban:status', 'allow'],
    'worker reports its card' => ['worker', $report, 'allow', null, '{wt}'],
    'worker reports another card' => ['worker', 'vendor/bin/kanban report ACME-A1B2C3 --status=review', 'deny', 'ACME-7K2M9Q only', '{wt}'],
    'worker moves a card' => ['worker', 'vendor/bin/kanban move ACME-7K2M9Q done', 'deny', 'main session', '{wt}'],
    'worker brings its stack up' => ['worker', 'vendor/bin/kanban stack up', 'allow', null, '{wt}'],
    'worker waits for its stack' => ['worker', 'php vendor/bin/kanban stack wait', 'allow', null, '{wt}'],
    'worker takes a stack down' => ['worker', 'vendor/bin/kanban stack down', 'deny', null, '{wt}'],
    'unbound worker reports' => ['worker:fresh-worker', 'vendor/bin/kanban report ACME-7K2M9Q --status=review', 'deny', 'EnterWorktree'],
    'evaluator records a verdict' => ['evaluator', 'vendor/bin/kanban verdict ACME-A1B2C3 --approve', 'allow', null, '{review}'],
    'evaluator reports' => ['evaluator', 'vendor/bin/kanban report ACME-A1B2C3 --status=review', 'deny', 'verdict', '{review}'],
    'other subagent lists' => ['other', 'vendor/bin/kanban list', null],
    'other subagent creates a card' => ['other', 'vendor/bin/kanban new project/work "x"', 'deny', 'main session'],
    'main moves a card' => ['main', 'vendor/bin/kanban move ACME-7K2M9Q review', null],
    'worker runs docker' => ['worker', 'docker ps', 'deny', 'kanban stack', '{wt}'],
    'worker runs docker compose via env' => ['worker', 'env docker compose up -d', 'deny', null, '{wt}'],
    'other subagent runs gh' => ['other', 'gh pr list', 'deny'],
    'evaluator uses sudo' => ['evaluator', 'sudo ls', 'deny', 'sudo', '{review}'],
    'main runs docker' => ['main', 'docker ps', null],
    'worker migrate:fresh on its own DB' => ['worker', 'php artisan migrate:fresh --seed', 'allow', null, '{wt}'],
    'worker migrate on its own DB' => ['worker', 'php artisan migrate --force', 'allow', null, '{wt}'],
    'worker seeds its own DB from main via cd' => ['worker', 'cd {wt} && php artisan db:seed --force', 'allow'],
    'evaluator migrate:fresh sharing main DB_PORT' => ['evaluator', 'php artisan migrate:fresh', null, null, '{review}'],
    'worker migrate:fresh from main' => ['worker', 'php artisan migrate:fresh', 'deny', 'outside your worktree'],
    'main migrate:fresh' => ['main', 'php artisan migrate:fresh', null],
    'worker redirects into the board' => ['worker', 'echo x > {board}/a.json', 'deny', 'CLI-only', '{wt}'],
    'main redirects into the board' => ['main', 'echo {} >> docs/kanban/project/work/ACME-7K2M9Q.json', 'deny', 'CLI-only'],
    'main tees into the board' => ['main', 'echo x | tee docs/kanban/x.json', 'deny', 'CLI-only'],
    'main sed -i on the board' => ['main', 'sed -i s/a/b/ docs/kanban/kanban.json', 'deny', 'CLI-only'],
    'main copies into the board' => ['main', 'cp /tmp/x docs/kanban/project/x.json', 'deny', 'CLI-only'],
    'main moves out of the board' => ['main', 'mv docs/kanban/project/work/ACME-7K2M9Q.json /tmp/', 'deny', 'CLI-only'],
    'main removes from the board' => ['main', 'rm -rf docs/kanban/project', 'deny', 'CLI-only'],
    'main touches the board' => ['main', 'touch docs/kanban/x', 'deny', 'CLI-only'],
    'main truncates on the board' => ['main', 'truncate -s 0 docs/kanban/kanban.json', 'deny', 'CLI-only'],
    'main links into the board' => ['main', 'ln -s /tmp/x docs/kanban/x', 'deny', 'CLI-only'],
    'main chmods the board' => ['main', 'chmod 600 docs/kanban/kanban.json', 'deny', 'CLI-only'],
    'main rsyncs into the board' => ['main', 'rsync -a /tmp/b/ docs/kanban/', 'deny', 'CLI-only'],
    'main dd into the board' => ['main', 'dd if=/dev/zero of=docs/kanban/x bs=1 count=1', 'deny', 'CLI-only'],
    'main find -delete on the board' => ['main', "find docs/kanban -name '*.json' -delete", 'deny', 'CLI-only'],
    'main find -exec rm on the board' => ['main', 'find docs/kanban -name x -exec rm {} +', 'deny', 'CLI-only'],
    'main reads the board' => ['main', 'cat docs/kanban/kanban.json | jq .key', null],
    'main copies from the board' => ['main', 'cp docs/kanban/kanban.json /tmp/k.json', null],
    'main writes .git' => ['main', 'echo x >> .git/info/exclude', null],
    'worker writes .git' => ['worker', 'echo x > {main}/.git/config', 'deny', '.git', '{wt}'],
    'worker touches the runtime' => ['worker', 'touch {main}/.git/laravel-kanban/x', 'deny', '.git', '{wt}'],
    'worker installs from main' => ['worker', 'npm install', 'deny', 'outside your worktree'],
    'worker lists main' => ['worker', 'ls -la', null],
    'worker reads a main file' => ['worker', 'cat README.md', 'allow'],
    'worker unknown cd' => ['worker', 'cd "$SOMEWHERE" && make', 'deny', 'outside your worktree', '{wt}'],
    'worker unresolvable write target' => ['worker', 'rm -rf "$X"', 'deny', 'cannot be resolved', '{wt}'],
    'worker writes to /dev/null' => ['worker', 'echo x > /dev/null 2>&1', 'allow', null, '{wt}'],
    'worker runs a check in its worktree' => ['worker', 'npm run check', null, null, '{wt}'],
    'worker source' => ['worker', 'source .env', 'deny', 'split it into plain', '{wt}'],
    'worker dot' => ['worker', '. ./script.sh', 'deny', 'split it into plain', '{wt}'],
    'worker pipes into sh' => ['worker', 'echo "git push" | sh', 'deny', 'split it into plain', '{wt}'],
    'worker unbalanced quotes' => ['worker', 'echo "abc', 'deny', 'split it into plain', '{wt}'],
    'main unbalanced quotes' => ['main', 'echo "abc', null],
    'main eval' => ['main', 'eval "git push"', null],
    'main redirect with case and function' => ['main', "f() { echo hi; }\ncase x in a) f > docs/kanban/y ;; esac", 'deny', 'CLI-only'],
    'pipeline ending in a push' => ['worker', 'tail -f storage/logs/laravel.log | grep -m1 ready && git push', 'deny', null, '{wt}'],
]);

it('treats Monitor like Bash', function () {
    $result = GuardSandbox::shared()->case('worker', 'Monitor', ['command' => 'git push', 'description' => 'x'], '{wt}');

    expect($result['decision'])->toBe('deny');
});
