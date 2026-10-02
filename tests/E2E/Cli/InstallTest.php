<?php

use Illuminate\Support\Facades\Artisan;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

it('creates the board on an orphan kanban branch at docs/kanban', function () {
    $sandbox = Sandbox::create();

    $output = $sandbox->install('ACME');

    expect($output)->toContain('created branch kanban at docs/kanban (key ACME)')
        ->and($sandbox->boardLog())->toBe(['Kanban: initialize'])
        ->and(trim($sandbox->boardGit('rev-parse', '--abbrev-ref', 'HEAD')))->toBe('kanban')
        ->and(trim($sandbox->boardGit('ls-files')))->toBe(implode("\n", [
            '.gitattributes', 'README.md', 'kanban.json', 'project/decisions/board.json', 'project/epic.json', 'project/work/board.json',
        ]))
        ->and(file_get_contents($sandbox->root.'/docs/kanban/.gitattributes'))->toBe("*.json merge=kanban text eol=lf\n")
        ->and(json_decode(file_get_contents($sandbox->root.'/docs/kanban/kanban.json'), true))->toMatchArray(['key' => 'ACME', 'max_parallel' => 6])
        ->and(file_get_contents($sandbox->root.'/.gitignore'))->toBe("/vendor/\n/docs/kanban/\n/.claude/worktrees\n")
        ->and(trim($sandbox->git('status', '--porcelain')))->toBe("M .gitignore\n?? .claude/\n?? CLAUDE.md")
        ->and(trim($sandbox->git('config', 'merge.kanban.driver')))->toBe("php '{$sandbox->root}/vendor/bin/kanban' merge-driver %O %A %B %P")
        ->and(trim($sandbox->git('config', 'core.hooksPath')))->toBe('vendor/petar-spasic/laravel-house/githooks')
        ->and(is_dir($sandbox->root.'/.git/laravel-house'))->toBeTrue();

    $unrelated = new Process(['git', 'merge-base', 'main', 'kanban'], $sandbox->root);
    expect($unrelated->run())->toBe(1);

    expect($sandbox->ok('validate'))->toContain('ok: 0 cards on 2 boards');
});

it('is idempotent', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $sandbox->git('config', 'core.hooksPath', '.githooks');

    $again = $sandbox->install('ACME');

    expect($again)->toContain('board attached at docs/kanban')
        ->toContain('core.hooksPath kept: .githooks')
        ->not->toContain('.gitignore +=')
        ->and($sandbox->boardLog())->toBe(['Kanban: initialize'])
        ->and(file_get_contents($sandbox->root.'/.gitignore'))->toBe("/vendor/\n/docs/kanban/\n/.claude/worktrees\n");
});

it('changes nothing on a dry run', function () {
    $sandbox = Sandbox::create();

    $output = $sandbox->install('ACME', ['--dry-run' => true]);

    expect($output)->toContain('would create orphan branch kanban')
        ->and(is_dir($sandbox->root.'/docs/kanban'))->toBeFalse()
        ->and(trim($sandbox->git('status', '--porcelain')))->toBe('');
});

it('refuses an invalid key', function () {
    $sandbox = Sandbox::create();
    app()->instance(Paths::class, Paths::discover($sandbox->root));

    expect(Artisan::call('kanban:install', ['--key' => 'p-1']))->toBe(2);
});

it('runs the same command classes standalone and under artisan', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $id = $sandbox->readyCard('Shared command classes');

    $standalone = $sandbox->ok('list');
    $prefixed = $sandbox->ok('kanban:list');
    Artisan::call('kanban:list');

    expect($standalone)->toContain("{$id} ready normal feature project/work Shared command classes")
        ->and($prefixed)->toBe($standalone)
        ->and(Artisan::output())->toBe($standalone);
});

it('finds the main checkout from a subdirectory and from a linked worktree', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $id = $sandbox->readyCard('Found from anywhere');
    mkdir($sandbox->root.'/app/Models', 0775, true);
    $sandbox->git('worktree', 'add', '-q', '-b', 'card/x', '.claude/worktrees/x');

    expect($sandbox->kanban(['show', $id], cwd: $sandbox->root.'/app/Models')->getOutput())->toContain('Found from anywhere')
        ->and($sandbox->kanban(['show', $id], cwd: $sandbox->root.'/.claude/worktrees/x')->getOutput())->toContain('Found from anywhere')
        ->and($sandbox->kanban(['show', $id], cwd: $sandbox->root.'/docs/kanban/project')->getOutput())->toContain('Found from anywhere');
});

it('reads the project config and .env without booting the app', function () {
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    mkdir($sandbox->root.'/config');
    file_put_contents($sandbox->root.'/config/kanban.php', "<?php return ['sync' => env('KANBAN_SYNC', 'off'), 'remote' => 'upstream'];\n");
    file_put_contents($sandbox->root.'/.env', "KANBAN_SYNC=on\n");

    expect($sandbox->ok('status', ['KANBAN_SYNC' => false]))->toContain(', sync on, ');
});
