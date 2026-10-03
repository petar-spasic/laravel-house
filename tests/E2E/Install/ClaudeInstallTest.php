<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(fn () => Steps::register(app()));

function settingsFixture(): string
{
    return json_encode([
        'env' => new stdClass,
        'hooks' => [
            'SessionStart' => [[
                'matcher' => 'startup',
                'hooks' => [
                    ['type' => 'command', 'command' => 'echo foreign-session'],
                    ['type' => 'command', 'command' => 'php', 'args' => ['${CLAUDE_PROJECT_DIR}/vendor/bin/kanban', 'hook', 'session-start'], 'timeout' => 5],
                ],
            ]],
            'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo foreign-guard']]]],
        ],
        'permissions' => ['allow' => ['Bash(npm run check)']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

it('merges settings, writes agents, .gitignore and the CLAUDE.md block, and is idempotent', function () {
    $sandbox = Sandbox::create();
    mkdir($sandbox->root.'/.claude/agents', 0775, true);
    file_put_contents($sandbox->root.'/.claude/settings.json', settingsFixture());
    file_put_contents($sandbox->root.'/.claude/agents/reviewer.md', "---\nname: reviewer\n---\nmine\n");
    file_put_contents($sandbox->root.'/CLAUDE.md', "# App\n\nHouse rules.\n\n<laravel-boost-guidelines>\nboost\n</laravel-boost-guidelines>\n");

    $output = $sandbox->install('ACME');

    expect($output)
        ->toContain("updated .claude/settings.json: hooks.SessionStart, hooks.SubagentStart, hooks.SubagentStop, hooks.PreToolUse, hooks.WorktreeCreate, hooks.WorktreeRemove, permissions.allow Bash(vendor/bin/kanban *), permissions.allow Bash({$sandbox->root}/vendor/bin/kanban *), permissions.allow Bash({$sandbox->root}/vendor/bin/kanban-exec *), attribution off")
        ->toContain('wrote .claude/agents/kanban-worker.md')
        ->toContain('wrote .claude/agents/kanban-evaluator.md')
        ->toContain('added the kanban block to CLAUDE.md')
        ->toContain('.gitignore += /.claude/worktrees')
        ->toContain('next: commit on main: .gitignore .claude/agents/ .claude/settings.json CLAUDE.md')
        ->toEndWith("next: restart Claude Code (agents and hooks load at session start); then `vendor/bin/kanban lease --takeover` if an old session holds the lease\n");

    $settings = json_decode(file_get_contents($sandbox->root.'/.claude/settings.json'), true);
    $kanban = fn (string $event) => ['type' => 'command', 'command' => 'php', 'args' => ['-d', 'display_errors=0', '-d', 'display_startup_errors=0', '${CLAUDE_PROJECT_DIR}/vendor/bin/kanban', 'hook', $event]];
    expect(file_get_contents($sandbox->root.'/.claude/settings.json'))->toContain('"env": {}')
        ->and($settings['hooks']['SessionStart'])->toBe([
            ['matcher' => 'startup|resume|clear|compact|fork', 'hooks' => [$kanban('session-start') + ['timeout' => 30]]],
            ['matcher' => 'startup', 'hooks' => [['type' => 'command', 'command' => 'echo foreign-session']]],
        ])
        ->and($settings['hooks']['PreToolUse'])->toBe([
            ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo foreign-guard']]],
            ['matcher' => 'Bash|Monitor|Read|Edit|Write|NotebookEdit|Glob|Grep|EnterWorktree|Agent', 'hooks' => [
                ['type' => 'command', 'command' => 'php', 'args' => ['-d', 'display_errors=0', '-d', 'display_startup_errors=0', '${CLAUDE_PROJECT_DIR}/vendor/petar-spasic/laravel-house/bin/kanban-guard'], 'timeout' => 10],
            ]],
        ])
        ->and($settings['hooks']['SubagentStop'])->toBe([['matcher' => 'kanban-worker|kanban-evaluator', 'hooks' => [$kanban('subagent-stop') + ['timeout' => 300]]]])
        ->and($settings['hooks']['WorktreeCreate'][0]['hooks'][0])->toBe($kanban('worktree-create') + ['timeout' => 120])
        ->and($settings['permissions']['allow'])->toBe(['Bash(npm run check)', 'Bash(vendor/bin/kanban *)', "Bash({$sandbox->root}/vendor/bin/kanban *)", "Bash({$sandbox->root}/vendor/bin/kanban-exec *)"])
        ->and($settings['attribution'])->toBe(['commit' => '', 'pr' => '', 'sessionUrl' => false]);

    $worker = file_get_contents($sandbox->root.'/.claude/agents/kanban-worker.md');
    expect($worker)->toStartWith("---\nname: kanban-worker\n")
        ->toContain('<!-- laravel-house:kanban-agent')
        ->toContain('a relative path')->not->toContain('isolation:')
        ->and(file_get_contents($sandbox->root.'/.claude/agents/kanban-evaluator.md'))->toContain("tools: Read, Grep, Glob, LSP, Bash, TodoWrite, Monitor, TaskStop, WebFetch, mcp__laravel-boost__search-docs\n")
        ->and(file_get_contents($sandbox->root.'/.claude/agents/reviewer.md'))->toBe("---\nname: reviewer\n---\nmine\n")
        ->and(file_get_contents($sandbox->root.'/.gitignore'))->toBe("/vendor/\n/docs/kanban/\n/.claude/worktrees\n");

    $claude = file_get_contents($sandbox->root.'/CLAUDE.md');
    expect($claude)->toStartWith("# App\n\nHouse rules.\n\n<!-- laravel-house:kanban:start -->\n## Kanban\n")
        ->toContain("<!-- laravel-house:kanban:end -->\n\n<laravel-boost-guidelines>\n");

    $before = array_map(fn (string $f) => file_get_contents($sandbox->root.'/'.$f), ['.claude/settings.json', 'CLAUDE.md', '.gitignore', '.claude/agents/kanban-worker.md']);
    $again = $sandbox->install('ACME');

    expect($again)->toContain('.claude/settings.json ok')
        ->toContain('.claude/agents/kanban-worker.md ok')
        ->toContain('CLAUDE.md kanban block ok')
        ->toContain('.gitignore ok')
        ->and(array_map(fn (string $f) => file_get_contents($sandbox->root.'/'.$f), ['.claude/settings.json', 'CLAUDE.md', '.gitignore', '.claude/agents/kanban-worker.md']))->toBe($before);
});

it('replaces an outdated CLAUDE.md block in place, never twice', function () {
    $sandbox = Sandbox::create();
    file_put_contents($sandbox->root.'/CLAUDE.md', "# App\n\n<!-- laravel-house:kanban:start -->\nold protocol\n<!-- laravel-house:kanban:end -->\n\n## Later section\n");

    expect($sandbox->install('ACME'))->toContain('updated the kanban block in CLAUDE.md');

    $claude = file_get_contents($sandbox->root.'/CLAUDE.md');
    expect(substr_count($claude, '<!-- laravel-house:kanban:start -->'))->toBe(1)
        ->and($claude)->not->toContain('old protocol')
        ->toStartWith("# App\n\n<!-- laravel-house:kanban:start -->\n## Kanban\n")
        ->toEndWith("<!-- laravel-house:kanban:end -->\n\n## Later section\n");
});

it('creates CLAUDE.md when the project has none', function () {
    $sandbox = Sandbox::create();

    expect($sandbox->install('ACME'))->toContain('created CLAUDE.md with the kanban block')
        ->and(file_get_contents($sandbox->root.'/CLAUDE.md'))->toStartWith("<!-- laravel-house:kanban:start -->\n## Kanban")
        ->and(file_get_contents($sandbox->root.'/.claude/settings.json'))->toStartWith("{\n  \"hooks\": {\n    \"SessionStart\": [");
});

it('keeps the block with Boost and lists the house in boost.json packages for the kanban skill', function () {
    $sandbox = Sandbox::create();
    file_put_contents($sandbox->root.'/boost.json', "{\n    \"agents\": [\n        \"claude_code\"\n    ],\n    \"guidelines\": true\n}\n");
    file_put_contents($sandbox->root.'/CLAUDE.md', "# App\n\n<!-- laravel-house:kanban:start -->\nold\n<!-- laravel-house:kanban:end -->\n\n<laravel-boost-guidelines>\nboost\n</laravel-boost-guidelines>\n");

    $output = $sandbox->install('ACME');

    expect($output)->toContain('updated the kanban block in CLAUDE.md')
        ->toContain('boost.json packages += petar-spasic/laravel-house')
        ->toContain('run `php artisan boost:update` in the project (no artisan here)')
        ->and(file_get_contents($sandbox->root.'/boost.json'))->toBe("{\n    \"agents\": [\n        \"claude_code\"\n    ],\n    \"guidelines\": true,\n    \"packages\": [\n        \"petar-spasic/laravel-house\"\n    ]\n}\n")
        ->and(file_get_contents($sandbox->root.'/CLAUDE.md'))->toStartWith("# App\n\n<!-- laravel-house:kanban:start -->\n## Kanban\n")
        ->toEndWith("<!-- laravel-house:kanban:end -->\n\n<laravel-boost-guidelines>\nboost\n</laravel-boost-guidelines>\n")
        ->and($sandbox->kanban(['doctor'])->getOutput())->toContain("ok CLAUDE.md kanban block\n")
        ->toContain("warn kanban skill not in .claude/skills yet (run `php artisan boost:update`)\n");

    expect($sandbox->install('ACME'))->toContain('boost.json ok')
        ->not->toContain('boost:update');
});

it('never overwrites an agent file that is not ours', function () {
    $sandbox = Sandbox::create();
    mkdir($sandbox->root.'/.claude/agents', 0775, true);
    file_put_contents($sandbox->root.'/.claude/agents/kanban-worker.md', "---\nname: kanban-worker\n---\nhand written\n");

    expect($sandbox->install('ACME'))->toContain('kept .claude/agents/kanban-worker.md: not ours (no laravel-house kanban marker)')
        ->and(file_get_contents($sandbox->root.'/.claude/agents/kanban-worker.md'))->toBe("---\nname: kanban-worker\n---\nhand written\n");
});

it('prints what would change on a dry run and writes nothing', function () {
    $sandbox = Sandbox::create();

    $output = $sandbox->install('ACME', ['--dry-run' => true]);

    expect($output)->toContain('would update .claude/settings.json: hooks.SessionStart')
        ->toContain('would write .claude/agents/kanban-worker.md')
        ->toContain('would create CLAUDE.md with the kanban block')
        ->toContain('would add to .gitignore: /docs/kanban/ /.claude/worktrees')
        ->not->toContain('restart Claude Code')
        ->and(is_dir($sandbox->root.'/.claude'))->toBeFalse()
        ->and(is_file($sandbox->root.'/CLAUDE.md'))->toBeFalse()
        ->and(trim($sandbox->git('status', '--porcelain')))->toBe('');
});

it('leaves commit attribution alone when githooks.reject_co_authored is off', function () {
    config(['kanban.githooks.reject_co_authored' => false]);
    $sandbox = Sandbox::create();

    $output = $sandbox->install('ACME');

    expect($output)->toContain('created .claude/settings.json: hooks.SessionStart')
        ->toContain("kanban.rejectCoAuthored: false\n")
        ->toContain('next: review .claude/settings.json (hooks, permissions.allow): ')
        ->not->toContain('attribution off')
        ->and(json_decode(file_get_contents($sandbox->root.'/.claude/settings.json'), true))->not->toHaveKey('attribution')
        ->and(trim($sandbox->git('config', 'kanban.rejectCoAuthored')))->toBe('false');
});

it('writes each agent\'s model and effort from kanban.agents, and doctor --fix follows the project config', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $worker = fn () => file_get_contents($sandbox->root.'/.claude/agents/kanban-worker.md');
    $evaluator = fn () => file_get_contents($sandbox->root.'/.claude/agents/kanban-evaluator.md');

    expect($worker())->toContain("model: sonnet\neffort: high\n")
        ->and($evaluator())->toContain("model: opus\neffort: medium\n")
        ->and($worker())->not->toContain('isolation:');

    @mkdir($sandbox->root.'/config', 0775, true);
    file_put_contents($sandbox->root.'/config/kanban.php', "<?php return ['agents' => ['worker' => ['model' => 'opus', 'effort' => 'max']]];\n");

    expect($sandbox->kanban(['doctor'])->getOutput())->toContain('warn .claude/agents/kanban-worker.md outdated')
        ->not->toContain('warn .claude/agents/kanban-evaluator.md');

    $sandbox->kanban(['doctor', '--fix']);

    expect($worker())->toContain("model: opus\neffort: max\n")
        ->and($evaluator())->toContain("model: opus\neffort: medium\n")
        ->and($sandbox->kanban(['doctor'])->getOutput())->toContain("ok .claude/agents/kanban-worker.md\n");
});
