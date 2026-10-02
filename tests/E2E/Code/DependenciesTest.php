<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;

function lock(string $file, array $packages): void
{
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode(['name' => 'acme', 'lockfileVersion' => 3, 'packages' => ['' => ['name' => 'acme'], ...$packages]]));
}

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $root = $this->code->root();
    $packages = [
        'node_modules/left-pad' => ['version' => '1.3.0'],
        'node_modules/@esbuild/darwin-arm64' => ['version' => '0.25.0', 'optional' => true],
    ];
    lock($root.'/package-lock.json', $packages);
    lock($root.'/node_modules/.package-lock.json', ['node_modules/left-pad' => ['version' => '1.3.0']]);
    lock($root.'/frontend/package-lock.json', []);
});

it('warns at start and in doctor about a dependency main lacks or holds behind its lockfile', function () {
    $code = $this->code;

    $start = $code->ok(['start', $code->sandbox->readyCard('Tag notes')]);

    expect($start)->toContain("warning: worktrees start without frontend/node_modules: install it in main\n")
        ->not->toContain('node_modules is behind');

    lock($code->root().'/node_modules/.package-lock.json', ['node_modules/left-pad' => ['version' => '1.2.0']]);
    $doctor = $code->kanban(['doctor'])->getOutput();

    expect($doctor)->toContain("warn node_modules is behind package-lock.json: run npm ci in main, or rebuild main's stack\n")
        ->toContain("warn worktrees start without frontend/node_modules: install it in main\n");
});
