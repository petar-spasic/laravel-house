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

/** laravel-deployment rendered with $modules into a fresh directory. */
function deployment(string $modules): string
{
    $out = Sandbox::tmp();
    $process = installScript(['--templates='.Sandbox::package().'/resources/boost/skills/laravel-deployment/templates',
        "--modules={$modules}", '--set', 'app=acme', '--set', 'app_name=Acme', '--set', 'php_version=8.5',
        '--set', 'web_port=8000', '--set', 'db_port=5433', '--set', 'redis_port=6380', '--set', 'ws_port=8001',
        '--set', 'domain=example.com', "--render-to={$out}"]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $out;
}

it('gives every stack its own session cookie and the https token helper, and seeds reference data on every boot', function (string $modules) {
    $out = deployment($modules);
    $prod = file_get_contents("{$out}/docker/docker-entrypoint.sh");
    $local = file_get_contents("{$out}/docker/docker-entrypoint-local.sh");

    expect(file_get_contents("{$out}/docker-compose.local.yml"))
        ->toContain('SESSION_COOKIE: ${COMPOSE_PROJECT_NAME}_session')
        ->toContain('KANBAN_GIT_TOKEN: ${KANBAN_GIT_TOKEN:-}')
        ->toContain('GIT_CONFIG_KEY_0: credential.helper')
        ->and($prod)->toMatch('/^php artisan db:seed --class=ReferenceDataSeeder --force\n(#.*\n)*case "\$\{DATABASE_SEED:-false\}" in$/m')
        ->and($prod)->toContain('true) php artisan db:seed --class=ProductionSeeder --force ;;')
        ->and($local)->toContain('false) php artisan db:seed --class=ReferenceDataSeeder --force ;;')
        ->and(file_get_contents("{$out}/snippets/env.dotenv"))->toContain('# KANBAN_GIT_TOKEN=');
})->with(['', 'htmx', 'spa,reverb,tenancy']);

it('answers git with KANBAN_GIT_TOKEN through the local compose credential helper, and stays silent without it', function () {
    preg_match("/GIT_CONFIG_VALUE_0: '(.*)'$/m", file_get_contents(deployment('htmx').'/docker-compose.local.yml'), $m);
    $helper = str_replace('$$', '$', $m[1]);
    $home = Sandbox::tmp();
    $fill = function (string|false $token) use ($helper, $home): string {
        $git = new Process(['git', '-c', "credential.helper={$helper}", 'credential', 'fill'], $home,
            ['HOME' => $home, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => false, 'SSH_ASKPASS' => false, 'KANBAN_GIT_TOKEN' => $token]);
        $git->setInput("protocol=https\nhost=git.example.test\n\n");
        $git->run();

        return $git->getOutput();
    };

    expect($fill('github_pat_example'))->toContain("username=x-access-token\npassword=github_pat_example\n")
        ->and($fill(false))->not->toContain('password=');
});

it('takes the test database lock for every top-level test run: a second run waits, a parallel worker does not', function (string $modules) {
    $app = Sandbox::tmp();
    mkdir("{$app}/tests");
    mkdir("{$app}/vendor");
    copy(deployment($modules).'/tests/bootstrap.php', "{$app}/tests/bootstrap.php");
    file_put_contents("{$app}/vendor/autoload.php", "<?php require '".Sandbox::package()."/vendor/autoload.php';\n");
    file_put_contents("{$app}/phpunit.xml", '<phpunit><php><env name="DB_DATABASE" value="acme_test" force="true"/></php></phpunit>');
    $run = fn (string $code, array $env = []) => new Process(['php', '-r', "require 'tests/bootstrap.php'; {$code}"], $app, $env + ['PARATEST' => false]);

    $holder = $run('echo "held\n"; fflush(STDOUT); usleep(1500000);');
    $holder->start();
    $holder->waitUntil(fn (string $type, string $out) => str_contains($out, 'held'));
    $worker = $run('echo "worker";', ['PARATEST' => '1']);
    $worker->run();
    $heldDuringWorker = $holder->isRunning();
    $second = $run('echo "second";');
    $second->run();

    expect($worker->getOutput())->toBe('worker')
        ->and($heldDuringWorker)->toBeTrue()
        ->and($second->getErrorOutput())->toContain('Waiting for the test database')
        ->and($second->getOutput())->toBe('second')
        ->and($holder->isRunning())->toBeFalse();
})->with(['', 'spa']);

it('keeps the spa e2e site on localhost, on the CSRF token path and behind one switch the prod boot refuses', function () {
    $out = deployment('spa');
    $caddy = file_get_contents("{$out}/docker/Caddyfile.local");
    $e2eSite = substr($caddy, strpos($caddy, ':8090 {'), strpos($caddy, ':8091 {') - strpos($caddy, ':8090 {'));
    $prod = file_get_contents("{$out}/docker/docker-entrypoint.sh");

    expect(substr_count($caddy, 'request_header -Sec-Fetch-Site'))->toBe(1)
        ->and($e2eSite)->toContain('request_header -Sec-Fetch-Site')
        ->and($caddy)->toContain("env APP_E2E true\n")->toContain("env APP_URL http://localhost:8090\n")
        ->toContain("env SANCTUM_STATEFUL_DOMAINS localhost:8090\n")->not->toContain('127.0.0.1:8090')
        ->and($prod)->toContain('[ ! -d resources/views ] || php artisan view:cache')->toContain('APP_E2E is for the local e2e site only')
        ->and(file_get_contents("{$out}/.env.prod.example"))->toMatch('/^TRUSTED_PROXIES=$/m')
        ->and(file_get_contents(deployment('htmx').'/docker/docker-entrypoint.sh'))->toContain("\nphp artisan view:cache\n")->not->toContain('APP_E2E');
});
