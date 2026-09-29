<?php

use PetarSpasic\Kanban\Tests\Support\GuardSandbox;

it('applies the Edit/Write table', function (string $actor, string $tool, string $path, ?string $decision, ?string $reason = null, ?string $cwd = null) {
    $key = $tool === 'NotebookEdit' ? 'notebook_path' : 'file_path';
    $result = GuardSandbox::shared()->case($actor, $tool, [$key => $path], $cwd);

    expect($result['decision'])->toBe($decision);
    if ($reason !== null) {
        expect($result['reason'])->toContain($reason);
    }
})->with([
    'main edits a card file' => ['main', 'Edit', '{board}/project/work/ACME-7K2M9Q.json', 'deny', 'vendor/bin/kanban'],
    'worker writes the board' => ['worker', 'Write', '{board}/kanban.json', 'deny', 'CLI-only'],
    'evaluator edits the board' => ['evaluator', 'Edit', '{board}/project/work/ACME-A1B2C3.json', 'deny', 'CLI-only'],
    'other subagent edits the board' => ['other', 'Edit', '{board}/project/epic.json', 'deny', 'CLI-only'],
    'main edits .git' => ['main', 'Edit', '{main}/.git/info/exclude', null],
    'worker edits .git' => ['worker', 'Edit', '{main}/.git/config', 'deny', '.git'],
    'other subagent writes a worktree .git file' => ['other', 'Write', '{wt}/.git', 'deny', '.git'],
    'main edits main' => ['main', 'Edit', '{main}/app/Thing.php', null],
    'bound worker edits main' => ['worker', 'Edit', '{main}/app/Thing.php', 'deny', 'inside your card\'s worktree'],
    'unbound worker edits main' => ['worker:fresh-worker', 'Write', '{main}/app/Thing.php', 'deny', 'EnterWorktree(path:'],
    'evaluator edits main' => ['evaluator', 'Edit', '{main}/app/Thing.php', 'deny', 'read-only'],
    'other subagent edits main' => ['other', 'Edit', '{main}/app/Thing.php', null],
    'main edits a worktree' => ['main', 'Edit', '{wt}/app/Thing.php', null],
    'worker edits its worktree' => ['worker', 'Edit', '{wt}/app/Thing.php', 'allow'],
    'worker edits its worktree by relative path' => ['worker', 'Write', 'app/New.php', 'allow', null, '{wt}'],
    'worker edits vendor in its worktree' => ['worker', 'Edit', '{wt}/vendor/laravel/x.php', 'deny', 'vendor/'],
    'evaluator edits its worktree' => ['evaluator', 'Edit', '{review}/app/Thing.php', 'deny', 'read-only'],
    'other subagent edits a linked worktree' => ['other', 'Edit', '{wt}/app/Thing.php', null],
    'worker edits another worktree' => ['worker', 'Edit', '{review}/app/Thing.php', 'deny', 'another worktree'],
    'evaluator edits another worktree' => ['evaluator', 'Edit', '{wt}/app/Thing.php', 'deny'],
    'worker writes outside' => ['worker', 'Write', '{outside}/notes.txt', null],
    'evaluator writes outside' => ['evaluator', 'Write', '{outside}/notes.txt', null],
    'worker edits a notebook in main' => ['worker', 'NotebookEdit', '{main}/nb.ipynb', 'deny'],
    'board path through ..' => ['main', 'Write', '{wt}/../../../docs/kanban/x.json', 'deny', 'CLI-only'],
]);

it('limits subagent writes in main to guard.main_write_paths when strict', function () {
    $sandbox = new GuardSandbox(['guard' => ['strict' => true, 'main_write_paths' => ['docs/notes/**']]]);

    expect($sandbox->case('other', 'Edit', ['file_path' => '{main}/app/Thing.php']))
        ->decision->toBe('deny')
        ->reason->toContain('docs/notes/**')
        ->and($sandbox->case('other', 'Write', ['file_path' => '{main}/docs/notes/a/b.md'])['decision'])->toBeNull()
        ->and($sandbox->case('main', 'Edit', ['file_path' => '{main}/app/Thing.php'])['decision'])->toBeNull();
});
