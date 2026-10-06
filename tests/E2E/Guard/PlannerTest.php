<?php

use PetarSpasic\LaravelHouse\Tests\Support\GuardSandbox;

it('binds a planner to its planning card on EnterWorktree, and to no other', function (array $input, ?string $card) {
    $sandbox = new GuardSandbox;

    $result = $sandbox->case('planner:p1', 'EnterWorktree', $input);
    $file = $sandbox->main.'/.git/laravel-house/agents/p1.json';

    expect($result['out'])->toBe('')
        ->and(is_file($file) ? json_decode(file_get_contents($file), true)['card'] : null)->toBe($card);
})->with([
    'its planning card' => [['path' => '{plan}'], GuardSandbox::PLANNING],
    'a doing card' => [['path' => '{wt}'], null],
]);

it('records the spawn of a planner for its planning card', function () {
    $sandbox = new GuardSandbox;

    $sandbox->case('main', 'Agent', ['subagent_type' => 'kanban-planner', 'prompt' => 'Card ACME-P1A2N3. Worktree {plan}']);

    expect(json_decode(file_get_contents($sandbox->main.'/.git/laravel-house/spawns/ACME-P1A2N3.json'), true))
        ->toMatchArray(['card' => GuardSandbox::PLANNING, 'agent_type' => 'kanban-planner', 'worktree' => '.claude/worktrees/acme-p1a2n3']);
});

it("routes a planner's shell into its card container", function () {
    $sandbox = new GuardSandbox;
    $sandbox->bind('planner-agent', 'kanban-planner', GuardSandbox::PLANNING);
    $container = $sandbox->stack(GuardSandbox::PLANNING);

    $result = $sandbox->case('planner', 'Bash', ['command' => 'php artisan route:list'], '{plan}');

    expect($result['decision'])->toBeNull()
        ->and($result['input']['command'])->toBe("{$sandbox->main}/vendor/bin/kanban-exec {$container} '{$sandbox->wt(GuardSandbox::PLANNING)}' 'php artisan route:list'");
});

it('lets a planner read its card and write its plan in .tmp', function (string $tool, array $input) {
    expect(GuardSandbox::shared()->case('planner', $tool, $input, '{plan}')['decision'])->toBeNull();
})->with([
    'read the code' => ['Read', ['file_path' => '{plan}/README.md']],
    'write the plan' => ['Write', ['file_path' => '{plan}/.tmp/plan.md', 'content' => '## Files']],
    'edit the plan' => ['Edit', ['file_path' => '{plan}/.tmp/plan.md', 'old_string' => 'a', 'new_string' => 'b']],
    'write a question' => ['Write', ['file_path' => '{plan}/.tmp/question.md', 'content' => '## Open question']],
]);

it("denies a planner's write to the code, and any file tool outside its card", function (string $tool, array $input, string $reason) {
    $result = GuardSandbox::shared()->case('planner', $tool, $input, '{plan}');

    expect($result['decision'])->toBe('deny')->and($result['reason'])->toContain($reason);
})->with([
    'edit the code' => ['Edit', ['file_path' => '{plan}/README.md', 'old_string' => 'a', 'new_string' => 'b'], 'a planner writes only its plan and question files'],
    'write a new file' => ['Write', ['file_path' => '{plan}/app/Plan.php', 'content' => '<?php'], 'a planner writes only its plan and question files'],
    "read another card's code" => ['Read', ['file_path' => '{wt}/README.md'], "outside your card's directory"],
]);
