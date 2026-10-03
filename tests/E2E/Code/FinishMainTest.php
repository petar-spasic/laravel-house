<?php

use PetarSpasic\LaravelHouse\Tests\Support\CodeSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Origin;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->code = CodeSandbox::create();
    $this->tmp = Sandbox::tmp();
    $this->steps = $this->tmp.'/steps.log';
    mkdir($this->tmp.'/bin');
    foreach (['composer', 'npm'] as $tool) {
        file_put_contents($this->tmp.'/bin/'.$tool, "#!/bin/sh\necho \"{$tool} \$* in \$(pwd)\" >> {$this->steps}\n[ ! -f {$this->tmp}/{$tool}.fails ]\n");
        chmod($this->tmp.'/bin/'.$tool, 0755);
    }
    $this->env = ['PATH' => $this->tmp.'/bin:'.dirname(__DIR__, 2).'/Support/FakeDocker:'.getenv('PATH')];
    $this->code->configure(['migrate' => "echo migrate >> {$this->steps}", 'finish' => ['after' => ["echo after >> {$this->steps}"]]]);
    $this->code->sandbox->git('commit', '-q', '-am', 'config');
});

function steps(string $file): array
{
    return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
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
    expect(steps($this->steps))->toBe(["composer install --no-interaction in {$root}", "npm ci in {$root}/frontend", 'migrate', 'after'])
        ->and($output)->toContain("install: npm ci in frontend ok\n");
});

it('skips the after-steps when an install fails', function () {
    $code = $this->code;
    touch($this->tmp.'/composer.fails');
    $id = $code->started('Bump deps');
    $code->commit($id, 'composer.lock', "{}\n");
    $code->approve($id);

    $run = $code->kanban(['finish', $id], $this->env);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain('install: composer install --no-interaction failed (exit 1)')
        ->toContain('after: skipped, `composer install --no-interaction` failed; fix it and run the rest by hand')
        ->and(steps($this->steps))->toHaveCount(1)
        ->and($code->sandbox->read($id)['stage'])->toBe('done');
});

it('runs the after-step that the merged card adds to config/kanban.php', function () {
    $code = $this->code;
    $id = $code->started('Seed the tags');
    $config = file_get_contents($code->root().'/config/kanban.php');
    $code->commit($id, 'config/kanban.php', str_replace("'echo after >> {$this->steps}',", "'echo after >> {$this->steps}', 'echo tags seeded >> {$this->steps}',", $config));
    $code->approve($id);

    // a card that changes the board's config merges only with the owner's --force
    $code->ok(['finish', $id, '--force'], $this->env);

    expect(steps($this->steps))->toBe(['migrate', 'after', 'tags seeded']);
});

it('warns about untracked files left in the main checkout', function () {
    $code = $this->code;
    $id = $code->started('Leftovers');
    $code->commit($id, 'feature.php', "<?php\n");
    $code->approve($id);
    file_put_contents($code->root().'/hot', "leftover\n");

    expect($code->ok(['finish', $id], $this->env))->toContain("warning: main has untracked files (an agent's leftovers?): hot; remove or commit them\n");
});

it('marks main red when finish.check fails after a merge, files one bug card and holds the next finish until it passes', function () {
    $code = $this->code;
    $green = $this->tmp.'/green';
    $code->configure(['migrate' => null, 'finish' => ['after' => [], 'check' => ["test -f {$green} || { echo 'Tests: 1 failed'; exit 1; }"]]]);
    $code->sandbox->git('commit', '-q', '-am', 'check');
    $first = $code->started('Tag notes');
    $code->commit($first, 'tags.php', "<?php\n");
    $code->approve($first);

    $run = $code->kanban(['finish', $first], $this->env);

    $marker = json_decode(file_get_contents($code->root().'/.git/laravel-house/main-check.json'), true);
    $bug = $code->sandbox->read($marker['card']);
    expect($run->getExitCode())->toBe(1)
        ->and($run->getErrorOutput())->toContain("on main; main is red, {$marker['card']} holds it")->toContain('Tests: 1 failed')
        ->and($code->sandbox->read($first)['stage'])->toBe('done')
        ->and($marker)->toMatchArray(['after' => $first, 'sha' => trim($code->sandbox->git('rev-parse', 'main'))])
        ->and($bug)->toMatchArray(['type' => 'bug', 'priority' => 'high', 'stage' => 'backlog'])
        ->and(array_values(array_filter($bug['labels'], fn ($l) => str_starts_with($l, 'area:'))))->toBe(array_values(array_filter($code->sandbox->read($first)['labels'], fn ($l) => str_starts_with($l, 'area:'))))
        ->and($bug['title'])->toStartWith("main red after {$first}: test -f")
        ->and($bug['acceptance'][0]['text'] ?? null)->toStartWith('`test -f')->toEndWith('` passes on main');

    $second = $code->started('Archive notes');
    $code->commit($second, 'archive.php', "<?php\n");
    $code->approve($second);
    $held = $code->kanban(['finish', $second], $this->env);

    expect($held->getExitCode())->toBe(3)
        ->and($held->getErrorOutput())->toContain("{$second}: main is red since ".substr($marker['sha'], 0, 7)." ({$first} merged)")
        ->toContain("finish {$marker['card']} first, or pass --force")
        ->and($code->sandbox->read($second)['stage'])->toBe('review');

    touch($green);
    expect($code->ok(['finish', $second], $this->env))->toContain("main green again: finish.check passes\n")
        ->and($code->root().'/.git/laravel-house/main-check.json')->not->toBeFile()
        ->and($code->sandbox->read($second)['stage'])->toBe('done');
});

it('merges while main is red with --force, keeping the one bug card', function () {
    $code = $this->code;
    $code->configure(['migrate' => null, 'finish' => ['after' => [], 'check' => ['exit 1']]]);
    $code->sandbox->git('commit', '-q', '-am', 'check');
    $first = $code->started('Tag notes');
    $code->commit($first, 'tags.php', "<?php\n");
    $code->approve($first);
    $code->kanban(['finish', $first], $this->env);
    $card = json_decode(file_get_contents($code->root().'/.git/laravel-house/main-check.json'), true)['card'];
    $second = $code->started('Archive notes');
    $code->commit($second, 'archive.php', "<?php\n");
    $code->approve($second);

    $run = $code->kanban(['finish', $second, '--force'], $this->env);

    expect($run->getExitCode())->toBe(1)
        ->and($code->sandbox->read($second)['stage'])->toBe('done')
        ->and(json_decode(file_get_contents($code->root().'/.git/laravel-house/main-check.json'), true))->toMatchArray(['card' => $card, 'after' => $first])
        ->and($code->ok(['status']))->toContain('main red since ')->toContain("({$card})");
});

it('pushes main once publish.every merges are not on the remote, and leaves a failed push to publish', function () {
    $code = $this->code;
    $origin = Origin::create();
    $code->configure(['sync' => 'off', 'publish' => ['every' => 2]]);
    $code->sandbox->git('commit', '-q', '-am', 'publish every 2');
    $code->sandbox->addRemote($origin);
    $finish = function (string $title) use ($code): array {
        $id = $code->started($title);
        $code->commit($id, strtolower(str_replace(' ', '-', $title)).'.php', "<?php\n");
        $code->approve($id);
        $run = $code->kanban(['finish', $id], $this->env);

        return [$id, $run];
    };

    [$first, $run] = $finish('Tag notes');
    expect($run->getOutput())->not->toContain('main: ')
        ->and($origin->log('main'))->not->toContain("{$first}: Tag notes");

    [$second, $run] = $finish('Archive notes');
    expect($run->getOutput())->toContain("main: 2 merges not on the remote (publish.every 2)\nmain: pushed to origin\n")
        ->and($origin->log('main'))->toContain("{$first}: Tag notes")->toContain("{$second}: Archive notes");

    $finish('Share notes');
    $code->sandbox->git('remote', 'set-url', 'origin', $code->root().'/missing.git');
    [$fourth, $run] = $finish('Print notes');
    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain("main: 2 merges not on the remote (publish.every 2)\nmain: not pushed (main: push failed: ")
        ->toContain('; run `kanban publish`')
        ->and($code->sandbox->read($fourth)['stage'])->toBe('done');
});

it('keeps the approval when main changed only files overlap_ignore lists alongside the branch', function () {
    $code = $this->code;
    @mkdir($code->root().'/docs', 0775, true);
    $code->commitMain('docs/guide.md', "# Guide\n\none\n\ntwo\n\nthree\n");
    $id = $code->started('Document tags');
    $code->commit($id, 'docs/guide.md', "# Guide\n\none, with tags\n\ntwo\n\nthree\n");
    $code->commit($id, 'tags.php', "<?php\n");
    $code->approve($id);
    $code->commitMain('docs/guide.md', "# Guide\n\none\n\ntwo\n\nthree, archived\n");

    $code->ok(['finish', $id], $this->env);

    expect($code->sandbox->read($id)['stage'])->toBe('done')
        ->and(file_get_contents($code->root().'/docs/guide.md'))->toBe("# Guide\n\none, with tags\n\ntwo\n\nthree, archived\n");
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
    $code->commitMain('other.txt', "other\n");
    $code->ok(['refresh', $third]);

    preg_match_all('/^review (\S+)/m', $code->ok(['status']), $m);

    expect($m[1])->toBe([$second, $first, $third]);
});
