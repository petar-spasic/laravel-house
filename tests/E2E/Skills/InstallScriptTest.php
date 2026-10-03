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

it('answers git with KANBAN_GIT_TOKEN through the local compose credential helper for origin\'s host only', function () {
    preg_match("/GIT_CONFIG_VALUE_0: '(.*)'$/m", file_get_contents(deployment('htmx').'/docker-compose.local.yml'), $m);
    $helper = str_replace('$$', '$', $m[1]);
    $repo = Sandbox::tmp();
    $env = ['HOME' => $repo, 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => false, 'SSH_ASKPASS' => false];
    (new Process(['git', 'init', '-q'], $repo, $env))->mustRun();
    (new Process(['git', 'remote', 'add', 'origin', 'https://git.example.test/acme/notes.git'], $repo, $env))->mustRun();
    $fill = function (string $host, string|false $token) use ($helper, $repo, $env): string {
        $git = new Process(['git', '-c', "credential.helper={$helper}", 'credential', 'fill'], $repo, $env + ['KANBAN_GIT_TOKEN' => $token]);
        $git->setInput("protocol=https\nhost={$host}\n\n");
        $git->run();

        return $git->getOutput();
    };

    expect($fill('git.example.test', 'github_pat_example'))->toContain("username=x-access-token\npassword=github_pat_example\n")
        ->and($fill('other.example.test', 'github_pat_example'))->not->toContain('password=')
        ->and($fill('git.example.test', false))->not->toContain('password=');
});

it('takes the test database lock for every top-level test run: a second run waits, a parallel worker does not', function (string $modules) {
    $app = Sandbox::tmp();
    mkdir("{$app}/tests");
    mkdir("{$app}/vendor");
    copy(deployment($modules).'/tests/bootstrap.php', "{$app}/tests/bootstrap.php");
    file_put_contents("{$app}/vendor/autoload.php", "<?php require '".Sandbox::package()."/vendor/autoload.php';\n");
    file_put_contents("{$app}/phpunit.xml", '<phpunit><php><env name="DB_DATABASE" value="acme_test" force="true"/></php></phpunit>');
    $run = fn (string $code, array $env = []) => new Process(['php', '-r', "require 'tests/bootstrap.php'; {$code}"], $app, $env + ['PARATEST' => false]);

    $holder = $run('echo "held\n"; fflush(STDOUT); while (! is_file("release")) { usleep(20000); }');
    $holder->start();
    $holder->waitUntil(fn (string $type, string $out) => str_contains($out, 'held'));
    $worker = $run('echo "worker";', ['PARATEST' => '1']);
    $worker->run();
    $second = $run('echo "second";');
    $second->start();
    $second->waitUntil(fn (string $type, string $out) => str_contains($out, 'Waiting for the test database'));
    $waiting = $second->isRunning();
    touch("{$app}/release");
    $second->wait();

    expect($worker->getOutput())->toBe('worker')
        ->and($waiting)->toBeTrue()
        ->and($second->getOutput())->toBe('second');
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

it('has a packages.md row for every npm package the spa frontend rules install', function () {
    $out = Sandbox::tmp();

    $process = installScript(['--modules=spa,reverb', '--set', 'app=acme', '--set', 'laravel_version=13',
        '--set', 'php_version=8.5', '--set', 'pest_version=5', "--render-to={$out}"]);
    preg_match_all('/`npm install -D ([^`]+)`/', (string) @file_get_contents("{$out}/frontend/CLAUDE.md"), $commands);
    $packages = array_values(array_filter(
        array_map(fn (string $word) => preg_replace('/(?<=.)@.*$/', '', $word), preg_split('/\s+/', implode(' ', $commands[1]))),
        // First-party packages need no row.
        fn (string $name) => ! str_starts_with($name, '-') && ! str_starts_with($name, '@laravel/') && $name !== 'laravel-echo',
    ));
    $rows = file_get_contents(Sandbox::package().'/resources/boost/skills/laravel-project-setup/references/packages.md');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($packages)->toContain('zod', 'cn', 'pusher-js')
        ->and(array_values(array_filter($packages, fn (string $name) => ! str_contains($rows, "| `{$name}`"))))->toBe([]);
});

it("names every placeholder a skill's templates hold in that skill's SKILL.md", function (string $skill) {
    $root = Sandbox::package().'/resources/boost/skills/'.$skill;
    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/templates', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        preg_match_all('/\{\{([a-z_]+)\}\}/', (string) file_get_contents((string) $file), $m);
        $used = [...$used, ...$m[1]];
    }
    $doc = (string) file_get_contents($root.'/SKILL.md');

    expect(array_values(array_filter(array_unique($used), fn (string $key) => ! str_contains($doc, "{{{$key}}}") && ! str_contains($doc, "`{$key}`") && ! str_contains($doc, "--set {$key}="))))->toBe([]);
})->with(['laravel-project-setup', 'laravel-deployment']);

/** The files of a `composer create-project laravel/laravel` checkout that --fresh edits, in a git repo with no commit. */
function skeleton(): string
{
    $repo = templateTree([
        'artisan' => '',
        '.env' => "APP_NAME=Laravel\nAPP_URL=http://localhost\n\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n",
        'config/database.php' => "<?php\n\nreturn ['default' => env('DB_CONNECTION', 'sqlite')];\n",
        'config/queue.php' => "<?php\n\nreturn ['default' => env('QUEUE_CONNECTION', 'database'), 'batching' => ['database' => env('DB_CONNECTION', 'sqlite')], 'failed' => ['database' => env('DB_CONNECTION', 'sqlite')]];\n",
        'config/cache.php' => "<?php\n\nreturn ['default' => env('CACHE_STORE', 'database')];\n",
        'config/session.php' => "<?php\n\nreturn ['driver' => env('SESSION_DRIVER', 'database')];\n",
        'composer.json' => json_encode(['name' => 'laravel/laravel', 'scripts' => ['dev' => ['npx concurrently'], 'setup' => ['composer install', 'npm install', 'npm run build'], 'post-update-cmd' => ['@php artisan vendor:publish --tag=laravel-assets --ansi --force']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        'package.json' => "{\n  \"scripts\": {\n    \"build\": \"vite build\"\n  }\n}\n",
        'vite.config.js' => "input: ['resources/css/app.css', 'resources/js/app.js'],\n",
        'resources/js/app.js' => "import './bootstrap';\n",
        'resources/views/welcome.blade.php' => "@vite(['resources/css/app.css', 'resources/js/app.js'])\n",
        'routes/web.php' => "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n",
        'tests/Unit/ExampleTest.php' => "<?php\n",
        'tests/Feature/ExampleTest.php' => "<?php\n",
        'AGENTS.md' => "# Agents\n",
        'CLAUDE.md' => "# Skeleton\n",
        'database/seeders/DatabaseSeeder.php' => "<?php\n",
        'app/Providers/HorizonServiceProvider.php' => "<?php\n",
        '.gitignore' => "/vendor\n.env\n",
    ]);
    copy("{$repo}/.env", "{$repo}/.env.example");
    (new Process(['git', 'init', '-q', '-b', 'main', $repo]))->mustRun();

    return $repo;
}

function fresh(string $repo, string $modules, array $extra = []): Process
{
    $sets = [];
    foreach (['app' => 'acme', 'app_name' => 'Acme Notes', 'web_port' => '8000', 'db_port' => '5433', 'redis_port' => '6380', 'laravel_version' => '13', 'php_version' => '8.5', 'pest_version' => '5'] as $key => $value) {
        array_push($sets, '--set', "{$key}={$value}");
    }
    $process = new Process(['php', Sandbox::package().'/resources/boost/skills/laravel-project-setup/scripts/install.php', $repo, "--modules={$modules}", ...$sets, '--fresh', ...$extra]);
    $process->run();

    return $process;
}

it('makes the fixed edits to a fresh htmx skeleton before writing the templates', function () {
    $repo = skeleton();

    $run = fresh($repo, 'htmx');

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain("fresh: deleted tests/Unit\n")->toContain("fresh: deleted resources/js/app.js\n")
        ->not->toContain('merge by hand')
        ->and(file_exists("{$repo}/AGENTS.md"))->toBeFalse()
        ->and(file_exists("{$repo}/tests/Feature"))->toBeFalse()
        ->and(file_get_contents("{$repo}/CLAUDE.md"))->not->toBe("# Skeleton\n")
        ->and(file_get_contents("{$repo}/.env"))
        ->toContain("APP_NAME=\"Acme Notes\"\nAPP_URL=http://localhost:8000\n\nDB_HOST=127.0.0.1\nDB_PORT=5433\nDB_DATABASE=acme\n\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6380\n")
        ->toContain("DB_USERNAME=acme\nDB_PASSWORD=acme\nADMIN_EMAILS=admin@acme.test\n")
        ->not->toContain('DB_CONNECTION')->not->toContain('SESSION_DRIVER')->not->toContain('CACHE_STORE')
        ->and(file_get_contents("{$repo}/.env.example"))->toContain("# ADMIN_EMAILS=admin@acme.test\n")
        ->and(file_get_contents("{$repo}/config/queue.php"))->toBe("<?php\n\nreturn ['default' => env('QUEUE_CONNECTION', 'redis'), 'batching' => ['database' => env('DB_CONNECTION', 'pgsql')], 'failed' => ['database' => env('DB_CONNECTION', 'pgsql')]];\n")
        ->and(file_get_contents("{$repo}/config/session.php"))->toContain("env('SESSION_DRIVER', 'redis')")
        ->and(file_get_contents("{$repo}/vite.config.js"))->toContain("'resources/js/app.ts'")
        ->and(json_decode(file_get_contents("{$repo}/boost.json"), true))->toBe(['agents' => ['claude_code'], 'cloud' => false, 'packages' => ['petar-spasic/laravel-house']])
        ->and(json_decode(file_get_contents("{$repo}/composer.json"), true)['scripts']['post-update-cmd'])->toContain('@php artisan boost:update --ansi')
        ->and(file_get_contents("{$repo}/package.json"))->toBe("{\n  \"scripts\": {\n    \"build\": \"vite build\",\n    \"check\": \"tsc\"\n  }\n}\n")
        ->and(file_get_contents("{$repo}/.gitignore"))->toBe("/vendor\n.env\n/.claude/settings.local.json\n.env.prod\n/frankenphp\n/public/frankenphp-worker.php\n");

    (new Process(['git', '-c', 'user.name=Acme', '-c', 'user.email=dev@acme.test', 'commit', '-q', '--allow-empty', '-m', 'init'], $repo))->mustRun();

    expect(fresh($repo, 'htmx')->getErrorOutput())->toContain('--fresh is for a fresh skeleton')
        ->and(fresh(skeleton(), 'htmx', ['--dry-run'])->getOutput())->toContain("would: deleted AGENTS.md\n");
});

it('drops the root Node toolchain for spa, and names a known line it cannot find', function () {
    $repo = skeleton();
    file_put_contents("{$repo}/config/cache.php", "<?php\n\nreturn ['default' => 'file'];\n");

    $run = fresh($repo, 'spa');

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain("merge by hand: config/cache.php (env('CACHE_STORE', 'redis'))\n")
        ->and(file_exists("{$repo}/package.json"))->toBeFalse()
        ->and(file_exists("{$repo}/resources/js"))->toBeFalse()
        ->and(file_get_contents("{$repo}/routes/web.php"))->not->toContain("view('welcome')")
        ->and(json_decode(file_get_contents("{$repo}/composer.json"), true)['scripts'])->not->toHaveKey('dev')
        ->and(json_decode(file_get_contents("{$repo}/composer.json"), true)['scripts']['setup'])->toBe(['composer install']);
});

function ports(array $args, array $env = []): Process
{
    $state = Sandbox::tmp();
    $process = new Process(['php', Sandbox::package().'/resources/boost/skills/laravel-project-setup/scripts/ports.php', ...$args], null, $env + [
        'PATH' => Sandbox::package().'/tests/Support/FakeDocker:'.getenv('PATH'), 'FAKE_DOCKER_DIR' => $state, 'KANBAN_STATE_DIR' => $state,
    ]);
    $process->run();

    return $process;
}

it('picks host ports nothing listens on and no container publishes, outside the kanban pool', function () {
    $listening = stream_socket_server('tcp://0.0.0.0:0');
    $held = (int) substr(strrchr(stream_socket_get_name($listening, false), ':'), 1);
    $state = Sandbox::tmp();
    file_put_contents("{$state}/stacks.json", json_encode(['version' => 1, 'pool' => ['base' => 5400, 'block' => 10, 'first' => 1, 'last' => 4], 'stacks' => []]));

    $out = ports(['--modules=reverb', "--avoid=8000,{$held}"], ['FAKE_DOCKER_BOUND' => '8001,6380', 'KANBAN_STATE_DIR' => $state])->getOutput();

    preg_match_all('/--set (\w+)=(\d+)/', $out, $m);
    $sets = array_combine($m[1], array_map('intval', $m[2]));
    expect(array_keys($sets))->toBe(['web_port', 'db_port', 'redis_port', 'ws_port'])
        ->and($sets['web_port'])->toBeGreaterThan(8001)
        ->and($sets['db_port'])->toBe(5450)
        ->and($sets['redis_port'])->toBeGreaterThan(6380)
        ->and($sets['ws_port'])->toBeGreaterThan($sets['web_port'])
        ->and($sets)->not->toContain($held);

    $spa = ports(['--modules=spa,reverb', '--avoid=8000,8001,8002,8003,8004,8005,8006,8007,8008,8009,8010'])->getOutput();
    expect($spa)->not->toContain('ws_port')->not->toContain('web_port=8080');
    fclose($listening);
});
