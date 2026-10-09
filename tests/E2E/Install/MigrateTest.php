<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeAgents;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(fn () => Steps::register(app()));

/** An installed sandbox carrying every name the board stored as the separate laravel-kanban package. */
function oldLayout(): array
{
    $sandbox = doctorSandbox();
    $root = $sandbox->root;
    rename($root.'/.git/laravel-house', $root.'/.git/laravel-kanban');
    file_put_contents($root.'/.git/laravel-kanban/deploy_key', "key\n");
    @mkdir($root.'/.git/laravel-kanban/agents', 0775, true);
    file_put_contents($root.'/.git/laravel-kanban/agents/a1.json', json_encode(['card' => 'ACME-1', 'stopped_at' => '2026-01-01T00:00:00Z']));
    foreach (['CLAUDE.md', ...array_map(fn (string $agent) => ".claude/agents/{$agent}.md", ClaudeAgents::AGENTS)] as $file) {
        file_put_contents($root.'/'.$file, str_replace(
            ['<!-- laravel-house:kanban:start -->', '<!-- laravel-house:kanban:end -->', '<!-- laravel-house:kanban-agent'],
            ['<!-- laravel-kanban:start -->', '<!-- laravel-kanban:end -->', '<!-- laravel-kanban:agent'],
            file_get_contents($root.'/'.$file),
        ));
    }
    @mkdir($root.'/.claude/worktrees/acme-1', 0775, true);
    file_put_contents($root.'/.claude/worktrees/acme-1/.env', "APP_NAME=app\n\n# laravel-kanban worktree stack (managed: rewritten by kanban, edit config/kanban.php stack.env instead)\nWEB_PORT=21010\n");
    $sandbox->git('config', 'core.hooksPath', 'vendor/petar-spasic/laravel-kanban/githooks');
    file_put_contents($root.'/boost.json', "{\n    \"packages\": [\n        \"petar-spasic/laravel-kanban\",\n        \"petar-spasic/laravel-house\"\n    ]\n}\n");
    file_put_contents($root.'/docker-compose.local.yml', file_get_contents($root.'/docker-compose.local.yml')."# GIT_SSH_COMMAND: ssh -i /app/.git/laravel-kanban/deploy_key\n");
    $xdg = Sandbox::tmp();
    mkdir($xdg.'/laravel-kanban');
    file_put_contents($xdg.'/laravel-kanban/stacks.json', '{"version":1,"stacks":{}}');

    return [$sandbox, ['XDG_STATE_HOME' => $xdg, 'KANBAN_STATE_DIR' => false]];
}

it('moves a project off the laravel-kanban names: doctor warns, doctor --fix migrates once', function () {
    [$sandbox, $env] = oldLayout();
    $root = $sandbox->root;

    expect($root.'/.git/laravel-house')->not->toBeDirectory()
        ->and(doctor($sandbox, env: $env)->getOutput())
        ->toContain("warn old laravel-kanban name: .git/laravel-kanban (run `vendor/bin/kanban doctor --fix`)\n")
        ->toContain("warn old laravel-kanban name: {$env['XDG_STATE_HOME']}/laravel-kanban/stacks.json")
        ->toContain('warn old laravel-kanban name: CLAUDE.md')
        ->toContain('warn old laravel-kanban name: .claude/agents/kanban-worker.md')
        ->toContain('warn old laravel-kanban name: .claude/worktrees/acme-1/.env')
        ->toContain('warn old laravel-kanban name: core.hooksPath vendor/petar-spasic/laravel-kanban/githooks')
        ->toContain('warn old laravel-kanban name: boost.json packages petar-spasic/laravel-kanban')
        ->toContain('warn old laravel-kanban name: docker-compose.local.yml');

    $fix = doctor($sandbox, ['--fix'], $env)->getOutput();

    expect($fix)
        ->toContain("fix: moved .git/laravel-kanban into .git/laravel-house\n")
        ->toContain("fix: moved {$env['XDG_STATE_HOME']}/laravel-kanban/stacks.json to {$env['XDG_STATE_HOME']}/laravel-house\n")
        ->toContain("fix: renamed the markers in CLAUDE.md\n")
        ->toContain("fix: renamed the markers in .claude/worktrees/acme-1/.env\n")
        ->toContain("fix: core.hooksPath: vendor/petar-spasic/laravel-house/githooks\n")
        ->toContain("fix: boost.json packages -= petar-spasic/laravel-kanban\n")
        ->toContain('fix: pointed docker-compose.local.yml at .git/laravel-house')
        ->not->toContain('old laravel-kanban name')
        ->and($root.'/.git/laravel-kanban')->not->toBeDirectory()
        ->and(file_get_contents($root.'/.git/laravel-house/deploy_key'))->toBe("key\n")
        ->and($root.'/.git/laravel-house/agents/a1.json')->toBeFile()
        ->and($env['XDG_STATE_HOME'].'/laravel-house/stacks.json')->toBeFile()
        ->and($env['XDG_STATE_HOME'].'/laravel-kanban')->not->toBeDirectory()
        ->and(trim($sandbox->git('config', 'core.hooksPath')))->toBe('vendor/petar-spasic/laravel-house/githooks')
        ->and(json_decode(file_get_contents($root.'/boost.json'), true)['packages'])->toBe(['petar-spasic/laravel-house'])
        ->and(file_get_contents($root.'/docker-compose.local.yml'))->toContain('/app/.git/laravel-house/deploy_key')->not->toContain('laravel-kanban')
        ->and(file_get_contents($root.'/.claude/worktrees/acme-1/.env'))->toContain("\n# laravel-house kanban worktree stack (managed:")
        ->and(substr_count(file_get_contents($root.'/CLAUDE.md'), '<!-- laravel-house:kanban:start -->'))->toBe(1)
        ->and(file_get_contents($root.'/.claude/agents/kanban-worker.md'))->toContain('<!-- laravel-house:kanban-agent');

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())->not->toContain('laravel-kanban')
        ->and(doctor($sandbox, env: $env)->getOutput())->toContain("ok CLAUDE.md kanban block\n")
        ->toContain("ok .claude/agents/kanban-worker.md\n")
        ->toContain("ok core.hooksPath vendor/petar-spasic/laravel-house/githooks\n")
        ->not->toContain('laravel-kanban');
});

it('leaves the old runtime directory while an agent there is still working', function () {
    [$sandbox, $env] = oldLayout();
    file_put_contents($sandbox->root.'/.git/laravel-kanban/agents/a2.json', json_encode(['card' => 'ACME-2']));

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain('fix: kept .git/laravel-kanban: 1 agent(s) still working; stop them, then run `vendor/bin/kanban doctor --fix`')
        ->toContain('fix: stopped: .git/laravel-kanban is still there (see above); attach waits for it, so no second deploy key is made')
        ->toContain('warn old laravel-kanban name: .git/laravel-kanban')
        ->not->toContain('pointed docker-compose.local.yml')
        ->and($sandbox->root.'/.git/laravel-kanban/deploy_key')->toBeFile()
        ->and($sandbox->root.'/.git/laravel-house/deploy_key')->not->toBeFile()
        ->and(file_get_contents($sandbox->root.'/docker-compose.local.yml'))->toContain('/app/.git/laravel-kanban/deploy_key');

    // The agent stops after the upgrade: the current runtime's copy of its record says so.
    @mkdir($sandbox->root.'/.git/laravel-house/agents', 0775, true);
    file_put_contents($sandbox->root.'/.git/laravel-house/agents/a2.json', json_encode(['card' => 'ACME-2', 'stopped_at' => '2026-01-01T00:00:00Z']));

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain("fix: moved .git/laravel-kanban into .git/laravel-house\n")
        ->toContain('fix: pointed docker-compose.local.yml at .git/laravel-house')
        ->not->toContain('stopped:')
        ->and(file_get_contents($sandbox->root.'/.git/laravel-house/deploy_key'))->toBe("key\n");
});

it('merges into a runtime directory that already exists: the current copy of a file wins, a different deploy key stays', function () {
    [$sandbox, $env] = oldLayout();
    $root = $sandbox->root;
    @mkdir($root.'/.git/laravel-house/agents', 0775, true);
    file_put_contents($root.'/.git/laravel-house/lease.json', '{"new":true}');
    file_put_contents($root.'/.git/laravel-kanban/lease.json', '{"old":true}');
    file_put_contents($root.'/.git/laravel-house/agents/b1.json', json_encode(['card' => 'ACME-3', 'stopped_at' => '2026-01-01T00:00:00Z']));

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())->toContain("fix: moved .git/laravel-kanban into .git/laravel-house\n")
        ->and($root.'/.git/laravel-kanban')->not->toBeDirectory()
        ->and(file_get_contents($root.'/.git/laravel-house/lease.json'))->toBe('{"new":true}')
        ->and(glob($root.'/.git/laravel-house/agents/*.json'))->toHaveCount(2);

    [$sandbox, $env] = oldLayout();
    mkdir($sandbox->root.'/.git/laravel-house');
    file_put_contents($sandbox->root.'/.git/laravel-house/deploy_key', "other\n");

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain('fix: moved .git/laravel-kanban into .git/laravel-house; kept deploy_key (a different copy is in .git/laravel-house)')
        ->toContain('fix: stopped: .git/laravel-kanban is still there')
        ->and(file_get_contents($sandbox->root.'/.git/laravel-house/deploy_key'))->toBe("other\n")
        ->and(file_get_contents($sandbox->root.'/.git/laravel-kanban/deploy_key'))->toBe("key\n");
});

it('keeps the machine registry while a repo it lists still runs the old package, then merges it into the current one', function () {
    [$sandbox, $env] = oldLayout();
    $other = Sandbox::tmp();
    mkdir($other.'/vendor/petar-spasic/laravel-kanban', 0775, true);
    $entry = fn (int $slot, string $repo) => ['slot' => $slot, 'project' => "acme-wt-{$slot}", 'repo' => $repo, 'worktree' => "{$repo}/.claude/worktrees/{$slot}", 'branch' => null, 'card' => null, 'ports' => [], 'created_at' => ''];
    $xdg = $env['XDG_STATE_HOME'];
    file_put_contents($xdg.'/laravel-kanban/stacks.json', json_encode(['version' => 1, 'stacks' => ['3' => $entry(3, $other), '4' => $entry(4, $other), '5' => $entry(4, $sandbox->root)]]));
    mkdir($xdg.'/laravel-house');
    file_put_contents($xdg.'/laravel-house/stacks.json', json_encode(['version' => 1, 'stacks' => ['4' => $entry(4, $sandbox->root)]]));

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain("fix: kept {$xdg}/laravel-kanban/stacks.json: still used by {$other} (on petar-spasic/laravel-kanban)")
        ->toContain("warn old laravel-kanban name: {$xdg}/laravel-kanban/stacks.json, still used by {$other}");

    rmdir($other.'/vendor/petar-spasic/laravel-kanban');

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain("fix: merged {$xdg}/laravel-kanban/stacks.json into {$xdg}/laravel-house; kept slot(s) 4 (taken there too)")
        ->and(array_keys(json_decode(file_get_contents($xdg.'/laravel-house/stacks.json'), true)['stacks']))->toEqualCanonicalizing([3, 4])
        ->and(json_decode(file_get_contents($xdg.'/laravel-house/stacks.json'), true)['stacks']['4']['repo'])->toBe($sandbox->root)
        ->and(array_keys(json_decode(file_get_contents($xdg.'/laravel-kanban/stacks.json'), true)['stacks']))->toBe([4]);

    expect(doctor($sandbox, ['--fix'], $env)->getOutput())
        ->toContain("fix: merged {$xdg}/laravel-kanban/stacks.json into {$xdg}/laravel-house; kept slot(s) 4 (taken there too)");
});
