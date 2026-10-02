<?php

use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

/** laravel-project-setup's install.php against a scratch repo; `--templates` and `--render-to` take paths. */
function installScript(array $args): Process
{
    $repo = Sandbox::tmp();
    touch($repo.'/artisan');
    $process = new Process(['php', Sandbox::package().'/resources/boost/skills/laravel-project-setup/scripts/install.php', $repo, ...$args]);
    $process->run();

    return $process;
}

function templateTree(array $files): string
{
    $dir = Sandbox::tmp();
    foreach ($files as $path => $text) {
        @mkdir(dirname("{$dir}/{$path}"), 0775, true);
        file_put_contents("{$dir}/{$path}", $text);
    }

    return $dir;
}

/** Every file under $dir, concatenated. */
function treeText(string $dir): string
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    return implode("\n", array_map(fn (SplFileInfo $f) => file_get_contents($f->getPathname()), iterator_to_array($files, false)));
}

it('resolves # blocks, writes scripts executable and skips a file that resolves to nothing', function () {
    $tree = templateTree([
        'core/run.sh' => "#!/bin/bash\n# if:spa\necho spa\n# unless:reverb\necho no-reverb\n# endif\n# endif\necho {{app}}\n",
        'core/.gitkeep' => '',
        'modules/spa/only.txt' => "spa file\n",
        'snippets/tenancy.php' => "# if:tenancy\n<?php\n# endif\n",
    ]);
    $out = Sandbox::tmp();

    $process = installScript(["--templates={$tree}", '--modules=spa', '--set', 'app=acme', "--render-to={$out}"]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents("{$out}/run.sh"))->toBe("#!/bin/bash\necho spa\necho no-reverb\necho acme\n")
        ->and(is_executable("{$out}/run.sh"))->toBeTrue()
        ->and(file_exists("{$out}/.gitkeep"))->toBeTrue()
        ->and(file_get_contents("{$out}/only.txt"))->toBe("spa file\n")
        ->and(file_exists("{$out}/snippets/tenancy.php"))->toBeFalse();
});

it('refuses a marker that is not alone on its line, and names a placeholder left', function () {
    $bad = templateTree(['core/a.sh' => "echo # if:spa\n# if:spa trailing\n# endif\n"]);
    $left = templateTree(['core/a.env' => "PORT={{web_port}}\n"]);

    $refused = installScript(["--templates={$bad}", '--set', 'app=acme', '--render-to='.Sandbox::tmp()]);
    $named = installScript(["--templates={$left}", '--set', 'app=acme', '--render-to='.Sandbox::tmp()]);

    expect($refused->getExitCode())->toBe(1)
        ->and($refused->getErrorOutput())->toContain('a.sh:2 a block marker must be alone on its line')
        ->and($named->getExitCode())->toBe(0)
        ->and($named->getOutput())->toContain('placeholders left in a.env: web_port');
});

it('renders laravel-deployment for every module set with nothing unresolved', function (string $modules) {
    $out = Sandbox::tmp();

    $process = installScript(['--templates='.Sandbox::package().'/resources/boost/skills/laravel-deployment/templates',
        "--modules={$modules}", '--set', 'app=acme', '--set', 'app_name=Acme', '--set', 'php_version=8.5',
        '--set', 'web_port=8000', '--set', 'db_port=5433', '--set', 'redis_port=6380', '--set', 'ws_port=8001',
        '--set', 'domain=example.com', "--render-to={$out}"]);
    $text = treeText($out);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->not->toContain('placeholders left')
        ->and($text)->not->toContain('{{')
        ->and($text)->not->toMatch('/^\s*(# (if|unless):|# endif|<!-- (if|unless):|<!-- endif)/m')
        ->and(is_executable("{$out}/docker/docker-entrypoint.sh"))->toBeTrue()
        ->and(file_exists("{$out}/docker/postgres/roles.sql"))->toBe(str_contains($modules, 'tenancy'))
        ->and(file_exists("{$out}/docker/e2e.sh"))->toBe(str_contains($modules, 'spa'))
        ->and(str_contains($text, 'reverb:start'))->toBe(str_contains($modules, 'reverb'));
})->with(['', 'htmx,islands', 'htmx,reverb,tenancy', 'spa', 'spa,reverb,tenancy']);
