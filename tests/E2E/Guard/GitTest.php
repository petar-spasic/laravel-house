<?php

use PetarSpasic\Kanban\Tests\Support\GuardSandbox;

$heredocCommit = <<<'SH'
git add app/Thing.php && git commit -m "$(cat <<'EOF'
ACME-7K2M9Q: don't push yet (push happens in kanban publish)

git push origin main is the main session's job
EOF
)"
SH;

it('applies the git class table', function (string $actor, string $command, ?string $decision, ?string $reason = null, ?string $cwd = null) {
    $result = GuardSandbox::shared()->case($actor, 'Bash', ['command' => $command], $cwd);

    expect($result['decision'])->toBe($decision, $result['out']);
    if ($reason !== null) {
        expect($result['reason'])->toContain($reason);
    }
})->with([
    'worker reads status in its worktree' => ['worker', 'git status', 'allow', null, '{wt}'],
    'worker reads the log from main' => ['worker', 'git log --oneline -5', 'allow'],
    'evaluator diffs its worktree' => ['evaluator', 'git diff main...HEAD', 'allow', null, '{review}'],
    'worker lists branches' => ['worker', 'git branch -a', 'allow', null, '{wt}'],
    'worker lists stashes' => ['worker', 'git stash list', 'allow', null, '{wt}'],
    'worker lists worktrees' => ['worker', 'git worktree list', 'allow', null, '{wt}'],
    'worker reads config' => ['worker', 'git config --get user.name', 'allow', null, '{wt}'],
    'worker commits in its worktree' => ['worker', 'git add -A && git commit -m "ACME-7K2M9Q: x"', 'allow', null, '{wt}'],
    'worker heredoc commit mentioning push' => ['worker', $heredocCommit, 'allow', null, '{wt}'],
    'worker cd into its worktree then commits' => ['worker', 'cd {wt} && git commit -m x', 'allow'],
    'worker cd main then commits' => ['worker', 'cd {main} && git commit -m x', 'deny', 'only inside your own worktree', '{wt}'],
    'worker commits from main' => ['worker', 'git commit -m x', 'deny', 'only inside your own worktree'],
    'worker commits in another worktree' => ['worker', 'git commit -m x', 'deny', null, '{review}'],
    'subshell cd does not leak' => ['worker', '(cd {main} && ls) && git commit -m x', null, null, '{wt}'],
    'worker pushes' => ['worker', 'git push', 'deny', 'main session', '{wt}'],
    'worker stashes' => ['worker', 'git stash', 'deny', null, '{wt}'],
    'worker creates a branch' => ['worker', 'git branch topic', 'deny', null, '{wt}'],
    'worker deletes a branch' => ['worker', 'git branch -D topic', 'deny', null, '{wt}'],
    'worker sets config' => ['worker', 'git config user.name X', 'deny', null, '{wt}'],
    'worker adds a worktree' => ['worker', 'git worktree add ../x', 'deny', null, '{wt}'],
    'worker resets' => ['worker', 'git reset --hard HEAD~1', 'deny', null, '{wt}'],
    'unknown subcommand or alias' => ['worker', 'git yolo', 'deny', null, '{wt}'],
    'git -C' => ['worker', 'git -C {wt} status', 'deny', '-C', '{wt}'],
    'GIT_DIR=' => ['worker', 'GIT_DIR={main}/.git git log', 'deny', 'GIT_DIR=', '{wt}'],
    'export GIT_WORK_TREE' => ['worker', 'export GIT_WORK_TREE={main}', 'deny', 'GIT_WORK_TREE=', '{wt}'],
    'git --git-dir=' => ['worker', 'git --git-dir={main}/.git status', 'deny', '--git-dir', '{wt}'],
    'git -c hooksPath' => ['worker', 'git -c core.hooksPath=/dev/null commit -m x', 'deny', '-c', '{wt}'],
    'commit --no-verify' => ['worker', 'git commit --no-verify -m x', 'deny', 'no-verify', '{wt}'],
    'commit -anm' => ['worker', 'git commit -anm x', 'deny', 'no-verify', '{wt}'],
    'commit message starting with -n' => ['worker', 'git commit -m -nothing', 'allow', null, '{wt}'],
    'add -f' => ['worker', 'git add -f .env', 'deny', 'add -f', '{wt}'],
    'bash -c git push' => ['worker', 'bash -c "git push"', 'deny', 'main session', '{wt}'],
    'sh -lc git push' => ['worker', "sh -lc 'git push origin main'", 'deny', null, '{wt}'],
    'xargs git push' => ['worker', 'echo origin | xargs git push', 'deny', null, '{wt}'],
    'xargs git' => ['worker', 'echo push | xargs -n1 git', 'deny', null, '{wt}'],
    'find -exec git push' => ['worker', 'find . -name x -exec git push \;', 'deny', null, '{wt}'],
    '$(git push)' => ['worker', 'echo $(git push)', 'deny', null, '{wt}'],
    'backticks' => ['worker', 'echo `git push`', 'deny', null, '{wt}'],
    'process substitution' => ['worker', 'cat <(git push)', 'deny', null, '{wt}'],
    'unquoted heredoc runs substitutions' => ['worker', "cat <<EOF\n\$(git push)\nEOF", 'deny', null, '{wt}'],
    'quoted heredoc is data' => ['worker', "cat <<'EOF'\n\$(git push)\nEOF", 'allow', null, '{wt}'],
    'eval' => ['worker', 'eval "git status"', 'deny', 'split it into plain, separate commands', '{wt}'],
    '$GIT push' => ['worker', '$GIT push', 'deny', 'split it into plain', '{wt}'],
    'env git push' => ['worker', 'env FOO=1 git push', 'deny', 'main session', '{wt}'],
    'backslash git' => ['worker', '\git push', 'deny', null, '{wt}'],
    'absolute git' => ['worker', '/usr/bin/git push', 'deny', null, '{wt}'],
    'quoted git' => ['worker', '"git" push', 'deny', null, '{wt}'],
    'ANSI-C quoted git' => ['worker', "\$'\\x67it' push", 'deny', null, '{wt}'],
    'wrappers' => ['worker', 'nohup timeout 5 nice -n 5 git push &', 'deny', null, '{wt}'],
    'git push inside a string is data' => ['worker', "echo 'git push'", 'allow', null, '{wt}'],
    'evaluator commits' => ['evaluator', 'git commit -m x', 'deny', 'read-only', '{review}'],
    'other subagent commits in a worktree' => ['other', 'git commit -m x', null, null, '{wt}'],
    'other subagent commits in main' => ['other', 'git commit -m x', 'deny'],
    'other subagent pushes' => ['other', 'git push', 'deny', 'main session'],
    'main pushes' => ['main', 'git push origin main kanban', null],
    'main uses -c' => ['main', 'git -c core.pager=cat log', null],
    'main commits on the board via -C' => ['main', 'git -C docs/kanban commit -m x', 'deny', 'CLI-only'],
    'main resets the board' => ['main', 'cd docs/kanban && git reset --hard', 'deny', 'CLI-only'],
    'main reads the board log' => ['main', 'git log', null, null, '{board}'],
]);
