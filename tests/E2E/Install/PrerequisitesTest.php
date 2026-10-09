<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

beforeEach(fn () => Steps::register(app()));

function bareOrigin(Sandbox $sandbox, bool $pushMain): string
{
    $origin = Sandbox::tmp().'/origin.git';
    (new Process(['git', 'init', '-q', '--bare', '-b', 'main', $origin]))->mustRun();
    $sandbox->git('remote', 'add', 'origin', $origin);
    $pushMain && $sandbox->git('push', '-q', 'origin', 'main');

    return $origin;
}

it('checks the prerequisites and installs nothing', function () {
    $sandbox = Sandbox::create();
    bareOrigin($sandbox, pushMain: true);
    file_put_contents($sandbox->root.'/composer.json', json_encode(['require' => ['petar-spasic/laravel-house' => '^0.6']]));
    file_put_contents($sandbox->root.'/README.md', "# changed\n");

    $out = $sandbox->install('ACME', ['--check' => true]);

    expect($out)->toMatch('/^ok git \d+\.\d+/m')
        ->toContain("warn petar-spasic/laravel-house is in require: move it to require-dev\n")
        ->toContain('warn no boost.json: ')
        ->toContain('warn main has uncommitted changes: ')
        ->toContain("ok origin reachable\n")
        ->toContain("warn no docker-compose.local.yml: cards get a clone of main and no stack, and the merge queue merges nothing\n")
        ->not->toContain('fail ')
        ->and(is_dir($sandbox->root.'/docs/kanban'))->toBeFalse()
        ->and(file_exists($sandbox->root.'/.claude/settings.json'))->toBeFalse();
});

it('stops the install on an empty origin, which would make the board branch the default', function () {
    $sandbox = Sandbox::create();
    bareOrigin($sandbox, pushMain: false);

    expect(fn () => $sandbox->install('ACME'))->toThrow(RuntimeException::class, 'fail origin is empty: push main first')
        ->and(is_dir($sandbox->root.'/docs/kanban'))->toBeFalse();

    $sandbox->git('push', '-q', 'origin', 'main');

    expect($sandbox->install('ACME'))->not->toContain('fail ')
        ->and(is_dir($sandbox->root.'/docs/kanban'))->toBeTrue();
});

it('refuses an origin the container cannot resolve on its own once card stacks are on', function () {
    $sandbox = Sandbox::create();
    $origin = bareOrigin($sandbox, pushMain: true);
    file_put_contents($sandbox->root.'/docker-compose.local.yml', "services: {}\n");
    $sandbox->git('remote', 'set-url', 'origin', 'acme:notes.git');
    $sandbox->git('config', 'url.'.$origin.'.insteadOf', 'acme:notes.git');

    expect(fn () => $sandbox->install('ACME', ['--check' => true]))
        ->toThrow(RuntimeException::class, "fail origin is rewritten by a url.*.insteadOf rule, which the container does not have: put {$origin} in .git/config");
});
