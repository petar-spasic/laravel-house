<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $this->code->defaults = ['FAKE_DOCKER_SERVE' => '1'];
    $this->tmp = Sandbox::tmp();
    $this->steps = $this->tmp.'/steps.log';
    mkdir($this->tmp.'/bin');
    foreach (['composer', 'npm'] as $tool) {
        file_put_contents($this->tmp.'/bin/'.$tool, "#!/bin/sh\necho \"{$tool} \$* in \$(pwd)\" >> {$this->steps}\n[ ! -f {$this->tmp}/{$tool}.fails ]\n");
        chmod($this->tmp.'/bin/'.$tool, 0755);
    }
    $this->env = ['PATH' => $this->tmp.'/bin:'.dirname(__DIR__, 2).'/Support/FakeDocker:'.getenv('PATH')];
    $this->configure = function (array $overrides = []): void {
        $this->code->configure(array_replace_recursive(['migrate' => "echo migrate >> {$this->steps}", 'finish' => ['after' => ["echo after >> {$this->steps}"]],
            'gates' => ['report' => []]], $overrides));
        $this->code->sandbox->git('commit', '-q', '-am', 'config');
        $this->code->sandbox->git('push', '-q', 'origin', 'main');
    };
    $this->origin = Origin::create();
    $this->code->sandbox->addRemote($this->origin);
    ($this->configure)();
});

/** The steps run in the main checkout: the merge clone's installs left out. */
function mainSteps(string $file): array
{
    return array_values(array_filter(is_file($file) ? explode("\n", (string) file_get_contents($file)) : [], fn (string $l) => $l !== '' && ! str_contains($l, '/_merge')));
}

it('installs what a changed lockfile needs, in its directory, before it migrates and runs the after-steps', function () {
    $code = $this->code;
    $id = $code->started('Bump deps');
    @mkdir($code->worktree($id).'/frontend', 0775, true);
    $code->commit($id, 'frontend/package-lock.json', "{}\n");
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);

    $output = $code->ok(['finish', $id], $this->env);

    $root = $code->root();
    expect(mainSteps($this->steps))->toBe(["composer install --no-interaction in {$root}", "npm ci in {$root}/frontend", 'migrate', 'after'])
        ->and($output)->toContain("install: npm ci in frontend ok\n");
});

it('skips the after-steps when an install fails, the card done', function () {
    $code = $this->code;
    $id = $code->started('Bump deps');
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);
    // fails in the main checkout only: the merge clone's install passed
    file_put_contents($this->tmp.'/bin/composer', "#!/bin/sh\necho \"composer \$* in \$(pwd)\" >> {$this->steps}\ncase \"\$(pwd)\" in */_merge) ;; *) exit 1 ;; esac\n");

    $run = $code->kanban(['finish', $id], $this->env);

    expect($run->getExitCode())->toBe(10)
        ->and($run->getErrorOutput())->toContain('install: composer install --no-interaction failed (exit 1)')
        ->toContain('after: skipped, `composer install --no-interaction` failed; fix it and run the rest by hand')
        ->and(mainSteps($this->steps))->toHaveCount(1)
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('keeps going after a step that throws once the card merged, and exits 10 with the card done and torn down', function () {
    $code = $this->code;
    $id = $code->started('Break the config');
    $branch = $code->sandbox->read($id)['work']['branch'];
    $wt = $code->worktree($id);
    $code->commit($id, 'docker/Dockerfile', "FROM php\n");
    $code->commit($id, 'config/kanban.php', "<?php\n\nthrow new RuntimeException('config/kanban.php is broken');\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id], $this->env);

    // every later CLI call loads the broken config: read the files
    expect($run->getExitCode())->toBe(10)
        ->and($run->getOutput())->toContain("merged {$id} into main")->toContain("{$id} review→done")->toContain('removed worktree')
        ->toContain('rebuild main: docker/Dockerfile changed')
        ->and($run->getErrorOutput())->toContain("install: RuntimeException: config/kanban.php is broken\nafter: skipped, the install step failed")
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'done', 'blocked' => null])
        ->and(is_dir($wt))->toBeFalse()
        ->and(trim($code->sandbox->git('branch', '--list', $branch)))->toBe('');
});

it("runs migrate and the after-steps in main's stack once it is up, and exits 10 when one fails", function () {
    $code = $this->code;
    ($this->configure)(['finish' => ['after' => ["echo seed >> {$this->steps}; echo 'layout off by 0.6%' >&2; exit 4"]]]);
    $id = $code->started('Seed layouts');
    $code->commit($id, 'docker/Dockerfile', "FROM php\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id], $this->env);

    $root = $code->root();
    $compose = "compose --project-directory {$root} -f {$root}/docker-compose.local.yml -p acme-local";
    $calls = $code->calls();
    $at = fn (string $call) => array_search($call, $calls, true);
    expect($run->getExitCode())->toBe(10)
        ->and($run->getOutput())->toContain("after: echo migrate >> {$this->steps} ok\n")
        ->and($run->getErrorOutput())->toContain("after: echo seed >> {$this->steps}; echo 'layout off by 0.6%' >&2; exit 4 failed (exit 4): layout off by 0.6%")
        ->and(mainSteps($this->steps))->toBe(['migrate', 'seed'])
        ->and($at("{$compose} up -d --force-recreate --wait"))->toBeInt()
        ->and($at("{$compose} up -d --wait --no-recreate"))->toBeGreaterThan($at("{$compose} up -d --force-recreate --wait"))
        ->and($at("{$compose} exec -T app sh -c echo migrate >> {$this->steps}"))->toBeGreaterThan($at("{$compose} up -d --wait --no-recreate"))
        ->and($code->sandbox->read($id))->toMatchArray(['stage' => 'done', 'blocked' => null]);
});

it("rebuilds main's stack when the merge touches lockfiles, docker files or the stack compose file", function () {
    $code = $this->code;
    ($this->configure)(['finish' => ['install' => []]]);
    $root = realpath($code->root());
    $id = $code->started('Bump deps');
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);

    $before = count($code->calls());
    expect($code->ok(['finish', $id], $this->env))->toContain("rebuild main: composer.lock changed; building its images, main keeps serving\n"
        ."rebuild main: recreating its containers (`docker compose -p acme-local ps` follows them)\nrebuilt main's stack acme-local\n")
        ->and(array_values(array_filter(array_slice($code->calls(), $before), fn ($call) => str_contains($call, ' -p acme-local ') && ! str_contains($call, ' exec ') && ! str_contains($call, '--no-recreate'))))->toBe([
            "compose --project-directory {$root} -f {$root}/docker-compose.local.yml -p acme-local build",
            "compose --project-directory {$root} -f {$root}/docker-compose.local.yml -p acme-local up -d --force-recreate --wait",
        ]);

    $frontend = $code->started('Bump frontend deps');
    @mkdir($code->worktree($frontend).'/frontend', 0775, true);
    $code->commit($frontend, 'frontend/package-lock.json', "{}\n");
    $code->approve($frontend);
    $before = count($code->calls());
    // a failed rebuild is a step that failed after the push: exit 10, the card done
    $failed = $code->kanban(['finish', $frontend], $this->env + ['FAKE_DOCKER_FAIL' => 'build']);
    expect($failed->getExitCode())->toBe(10)
        ->and($failed->getOutput())->toContain('rebuild main: frontend/package-lock.json changed; building its images')
        ->and($failed->getErrorOutput())->toContain('rebuild main failed to build, main runs on its old images: failed to solve')
        ->toContain('; run `docker compose -f docker-compose.local.yml build && docker compose -f docker-compose.local.yml up -d --force-recreate --wait`')
        ->and($code->sandbox->read($frontend)['stage'])->toBe('done')
        ->and(collect(array_slice($code->calls(), $before))->contains(fn ($call) => str_contains($call, '-p acme-local up -d --force-recreate')))->toBeFalse();

    $compose = $code->started('Publish the web port on IPv4 only');
    $code->commit($compose, 'docker-compose.local.yml', file_get_contents($code->root().'/docker-compose.local.yml')."# ipv4\n");
    $code->approve($compose);
    $before = count($code->calls());
    expect($code->ok(['finish', $compose, '--no-rebuild'], $this->env))->toContain('rebuild main: docker-compose.local.yml changed; run `docker compose -f docker-compose.local.yml build && docker compose -f docker-compose.local.yml up -d --force-recreate --wait`')
        ->and(collect(array_slice($code->calls(), $before))->contains(fn ($call) => str_contains($call, ' -p acme-local up -d --force-recreate')))->toBeFalse();
});

it('warns about untracked files left in the main checkout', function () {
    $code = $this->code;
    $id = $code->started('Leftovers');
    $code->commit($id, 'feature.php', "<?php\n");
    $code->approve($id);
    file_put_contents($code->root().'/hot', "leftover\n");

    expect($code->ok(['finish', $id], $this->env))->toContain("warning: main has untracked files (an agent's leftovers?): hot; remove or commit them\n");
});

it("follows the remote's main with the main checkout and its after-steps, and refuses one that diverged or is on another branch", function () {
    $code = $this->code;
    $side = $this->origin->clone('side');
    file_put_contents($side->root.'/composer.lock', "{}\n");
    $side->git('add', 'composer.lock');
    $side->git('commit', '-q', '-m', 'Bump deps elsewhere');
    $side->git('push', '-q', 'origin', 'HEAD:main');
    $sha = trim($side->git('rev-parse', 'HEAD'));

    $followed = $code->ok(['finish', '--follow'], $this->env);
    $at = trim($code->sandbox->git('rev-parse', 'main'));
    $again = $code->ok(['finish', '--follow'], $this->env);
    $code->sandbox->git('checkout', '-q', '-b', 'other');
    $branch = $code->kanban(['finish', '--follow'], $this->env);
    $code->sandbox->git('checkout', '-q', 'main');
    $code->commitMain('local.txt', "local\n");
    $ahead = $code->kanban(['finish', '--follow'], $this->env);
    $side->git('commit', '-q', '--allow-empty', '-m', 'More elsewhere');
    $side->git('push', '-q', 'origin', 'HEAD:main');
    $diverged = $code->kanban(['finish', '--follow'], $this->env);

    expect($followed)->toContain('main checkout at '.substr($sha, 0, 7)."\n")
        ->and($at)->toBe($sha)
        ->and(mainSteps($this->steps))->toBe(["composer install --no-interaction in {$code->root()}", 'migrate', 'after'])
        ->and($again)->toBe('')
        ->and($branch->getExitCode())->toBe(10)
        ->and($branch->getErrorOutput())->toContain("main checkout not moved: the main checkout is on 'other', not main")
        ->and($ahead->getExitCode())->toBe(10)
        ->and($ahead->getErrorOutput())->toContain("it has commits the remote's main lacks; `kanban publish` pushes them, or drop them")
        ->and($diverged->getExitCode())->toBe(10)
        ->and($diverged->getErrorOutput())->toContain("it and the remote's main diverged; merge the remote's main into it by hand, then `kanban publish`");
});

it('lists review cards that hold an approval first, oldest approval first', function () {
    $code = $this->code;
    $first = $code->started('Tag notes');
    $second = $code->started('Archive notes');
    $third = $code->started('Share notes');
    foreach ([$first, $second, $third] as $id) {
        $code->commit($id, strtolower($id).'.php', "<?php\n");
    }
    $code->approve($first, at: '2026-05-02T10:00:00.000+00:00');
    $code->approve($second, at: '2026-05-01T10:00:00.000+00:00');
    $code->approve($third);

    preg_match_all('/^review (\S+)/m', $code->ok(['status']), $m);

    expect($m[1])->toBe([$second, $first, $third]);
});
