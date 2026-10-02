<?php

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Steps;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(fn () => Steps::register(app()));

/** An installed project whose origin is $url, with the local compose file the container sync lives in. */
function keyedProject(string $url = 'git@github.com:acme/notes.git', bool $compose = true): Sandbox
{
    $sandbox = Sandbox::create();
    $sandbox->install('ACME');
    $sandbox->git('remote', 'add', 'origin', $url);
    if ($compose) {
        file_put_contents($sandbox->root.'/docker-compose.local.yml', "services: {}\n");
    }

    return $sandbox;
}

function keyFile(Sandbox $sandbox, string $name = 'deploy_key'): string
{
    return $sandbox->root.'/.git/laravel-house/'.$name;
}

it('creates a deploy key for the container on attach, says how to add it, and keeps it on this machine', function () {
    $sandbox = keyedProject();

    $out = $sandbox->ok('attach');

    expect(is_file(keyFile($sandbox)))->toBeTrue()
        ->and(fileperms(keyFile($sandbox)) & 0777)->toBe(0600)
        ->and($out)->toContain('deploy key for the container')->toContain('ssh-ed25519 ')
        ->toContain('https://github.com/acme/notes/settings/keys/new')->toContain('Allow write access')
        ->toContain('gh repo deploy-key add .git/laravel-house/deploy_key.pub --allow-write');
});

it('keeps the key it made: a second attach neither replaces nor repeats it', function () {
    $sandbox = keyedProject();
    $sandbox->ok('attach');
    $before = sha1_file(keyFile($sandbox));

    $again = $sandbox->ok('attach');

    expect(sha1_file(keyFile($sandbox)))->toBe($before)->and($again)->not->toContain('ssh-ed25519');
});

it('makes no key where the container never syncs: https or local remotes, no local compose file, no remote', function (string $url, bool $compose) {
    $sandbox = keyedProject($url, $compose);

    $out = $sandbox->ok('attach');

    expect(file_exists(keyFile($sandbox)))->toBeFalse()->and($out)->not->toContain('deploy key');
})->with([
    'https' => ['https://github.com/acme/notes.git', true],
    'a local path' => ['/srv/git/notes.git', true],
    'no compose file' => ['git@github.com:acme/notes.git', false],
]);

it('says what a dry-run install would create', function () {
    $sandbox = Sandbox::create();
    $sandbox->git('remote', 'add', 'origin', 'ssh://git@git.example.test/acme/notes.git');
    file_put_contents($sandbox->root.'/docker-compose.local.yml', "services: {}\n");

    $out = $sandbox->install('ACME', ['--dry-run' => true]);

    expect($out)->toContain('would create a deploy key for the container sync')
        ->and(file_exists(keyFile($sandbox)))->toBeFalse();
});

it('lets doctor report whether the key is there', function () {
    $sandbox = keyedProject();

    $missing = $sandbox->kanban('doctor')->getOutput();
    $sandbox->ok('attach');
    $present = $sandbox->kanban('doctor')->getOutput();

    expect($missing)->toContain('warn no deploy key for the container sync')
        ->and($present)->toContain('ok deploy key .git/laravel-house/deploy_key');
});

it('lets doctor tell an https origin to set KANBAN_GIT_TOKEN for the container sync, and see it once set', function () {
    $sandbox = keyedProject('https://github.com/acme/notes.git');

    $unset = $sandbox->kanban('doctor')->getOutput();
    file_put_contents($sandbox->root.'/.env', "KANBAN_GIT_TOKEN=github_pat_example\n", FILE_APPEND);
    $set = $sandbox->kanban('doctor')->getOutput();

    expect($unset)->toContain('warn https origin: set KANBAN_GIT_TOKEN in .env')
        ->and($set)->toContain('ok https origin: the container syncs with KANBAN_GIT_TOKEN')->not->toContain('github_pat_example')
        ->and(keyedProject('https://github.com/acme/notes.git', compose: false)->kanban('doctor')->getOutput())->not->toContain('KANBAN_GIT_TOKEN');
});

it('hands GIT_SSH_COMMAND to the git that syncs, which is how the container picks up its key', function () {
    $sandbox = keyedProject('ssh://git@git.example.test/acme/notes.git');
    $log = $sandbox->root.'/ssh.log';
    $fake = $sandbox->root.'/fake-ssh';
    file_put_contents($fake, "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($log)."\nexit 255\n");
    chmod($fake, 0755);

    $sandbox->kanban('sync', ['KANBAN_SYNC' => 'on', 'GIT_SSH_COMMAND' => $fake.' -i key']);

    expect(file_get_contents($log))->toContain('-i key')->toContain('git.example.test');
});
