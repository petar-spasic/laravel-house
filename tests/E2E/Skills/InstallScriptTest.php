<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use Symfony\Component\Process\Process;

/** laravel-project-setup's install.php against a scratch repo. */
function installScript(array $args): Process
{
    $repo = Sandbox::tmp();
    touch($repo.'/artisan');

    return installPhp($repo, $args);
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

it('runs Playwright with the dot reporter unless the caller names one', function (array $args, string $expected) {
    $script = file_get_contents(deployment('spa').'/docker/e2e.sh');
    $tail = substr($script, (int) strpos($script, "\nreporter=") + 1);
    $dir = sys_get_temp_dir().'/e2e-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/node_modules/.bin", 0777, true);
    file_put_contents("{$dir}/node_modules/.bin/playwright", "#!/bin/bash\necho \"\$*\"\n");
    chmod("{$dir}/node_modules/.bin/playwright", 0755);

    $run = new Process(['bash', '-c', $tail, 'e2e.sh', ...$args], $dir);
    $run->run();

    expect(str_contains($script, "\nreporter="))->toBeTrue()
        ->and(trim($run->getOutput()))->toBe($expected);
})->with([
    'none' => [['e2e/a.spec.ts'], 'test --reporter=dot e2e/a.spec.ts'],
    'named' => [['--reporter=list', 'e2e/a.spec.ts'], 'test --reporter=list e2e/a.spec.ts'],
    'spaced' => [['--reporter', 'line'], 'test --reporter line'],
]);

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

it('type-checks the Playwright specs and config in the spa check script, stated once', function () {
    $out = Sandbox::tmp();

    $process = installScript(['--modules=spa', '--set', 'app=acme', '--set', 'laravel_version=13',
        '--set', 'php_version=8.5', '--set', 'pest_version=5', "--render-to={$out}"]);
    $rules = (string) @file_get_contents("{$out}/frontend/CLAUDE.md");
    $reference = file_get_contents(Sandbox::package().'/resources/boost/skills/laravel-project-setup/references/validation-export.md');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($rules)->toContain("typescript: { config: (c) => { c.include.push('../e2e/**/*.ts', '../playwright.config.ts'); } }")
        ->toContain('svelte-check --tsconfig ./tsconfig.json')
        ->and($reference)->not->toContain('svelte-check');
});

it("makes APP_URL's origin stateful in config, for every process of the stack", function (string $listed, string $url, array $expected) {
    $out = Sandbox::tmp();
    $process = installScript(['--modules=spa', '--set', 'app=acme', '--set', 'laravel_version=13',
        '--set', 'php_version=8.5', '--set', 'pest_version=5', "--render-to={$out}"]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $config = new Process(['php', '-r', 'function env($k, $d = null) { $v = getenv($k); return $v === false ? $d : $v; }
        function config($k) { return getenv("APP_URL"); }
        echo json_encode((require $argv[1])["stateful"]);', "{$out}/snippets/config-sanctum.php"], null, ['SANCTUM_STATEFUL_DOMAINS' => $listed, 'APP_URL' => $url]);
    $config->mustRun();

    expect(json_decode($config->getOutput(), true))->toBe($expected)
        ->and(file_get_contents(deployment('spa').'/docker/docker-entrypoint-local.sh'))->not->toContain('export SANCTUM_STATEFUL_DOMAINS');
})->with([
    'a LAN url' => ['localhost:8000,127.0.0.1:8000', 'http://192.0.2.10:8000', ['localhost:8000', '127.0.0.1:8000', '192.0.2.10:8000']],
    'listed already' => ['localhost:8000,127.0.0.1:8000', 'http://localhost:8000', ['localhost:8000', '127.0.0.1:8000']],
    'nothing listed' => ['', 'https://example.com', ['example.com']],
]);

it('ships and registers every global middleware the rules name', function (string $modules) {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    $render = Sandbox::tmp();
    $args = ["--modules={$modules}", '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5'];
    $install = installPhp($repo, $args);
    $rendered = installPhp($repo, [...$args, "--render-to={$render}"]);
    preg_match_all('/`(\w+)` (prepended|appended) globally/', (string) @file_get_contents("{$repo}/app/Http/CLAUDE.md"), $named, PREG_SET_ORDER);
    $bootstrap = (string) @file_get_contents("{$render}/snippets/bootstrap-app.php");

    expect($install->getExitCode())->toBe(0, $install->getErrorOutput())
        ->and($rendered->getExitCode())->toBe(0, $rendered->getErrorOutput())
        ->and(array_column($named, 1))->toBe(['RequestId', 'SecurityHeaders']);
    foreach ($named as [, $class, $how]) {
        $file = "{$repo}/app/Http/Middleware/{$class}.php";
        $lint = new Process(['php', '-l', $file]);
        $lint->run();

        expect($install->getOutput())->toContain("  app/Http/Middleware/{$class}.php\n")
            ->and($lint->getExitCode())->toBe(0, $lint->getOutput())
            ->and($bootstrap)->toContain("use App\\Http\\Middleware\\{$class};")
            ->toContain('$middleware->'.($how === 'prepended' ? 'prepend' : 'append')."({$class}::class);");
    }

    $headers = file_get_contents("{$repo}/app/Http/Middleware/SecurityHeaders.php");
    expect($headers)->toContain('$request->is($horizon, "{$horizon}/*")')->toContain("has('Content-Security-Policy')");
    str_contains($modules, 'spa')
        ? expect($headers)->toContain("default-src 'none'; frame-ancestors 'none'")->not->toContain('Vite')
        : expect($headers)->toContain('Vite::useCspNonce()')->not->toContain("default-src 'none'");
    expect(str_contains($headers, "'inline-speculation-rules'"))->toBe(str_contains($modules, 'htmx'));
})->with(['', 'htmx', 'htmx,islands', 'spa,tenancy']);

it('leaves the debug exception page without a CSP, and lets the Vite dev server serve images and fonts while hot', function (string $modules) {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    $install = installPhp($repo, ["--modules={$modules}", '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5']);
    expect($install->getExitCode())->toBe(0, $install->getErrorOutput());
    $namespace = 'HouseTest\\Headers'.md5($modules.$repo);
    eval(substr(str_replace('namespace App\\Http\\Middleware;', "namespace {$namespace};", (string) file_get_contents("{$repo}/app/Http/Middleware/SecurityHeaders.php")), 5));
    $class = "{$namespace}\\SecurityHeaders";
    $page = fn () => response('<script>boot()</script>', 500, ['Content-Type' => 'text/html'])->withException(new RuntimeException('boom'));
    $csp = fn () => (new $class)->handle(Request::create('/notes'), $page)->headers->get('Content-Security-Policy');

    config(['app.debug' => true]);
    expect($csp())->toBeNull();
    config(['app.debug' => false]);
    expect($csp())->toContain(str_contains($modules, 'spa') ? "default-src 'none'" : "script-src 'self' 'nonce-");

    if (! str_contains($modules, 'spa')) {
        $hot = Sandbox::tmp().'/hot';
        file_put_contents($hot, 'http://198.51.100.7:5173');
        Vite::useHotFile($hot);
        expect($csp())->toContain("img-src 'self' data: http://198.51.100.7:5173")->toContain("font-src 'self' http://198.51.100.7:5173");
    }
})->with(['htmx', 'spa']);

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

    return installPhp($repo, ["--modules={$modules}", ...$sets, '--fresh', ...$extra]);
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
        ->and(json_decode(file_get_contents("{$repo}/composer.json"), true)['scripts']['post-update-cmd'])
        ->toBe(['@php artisan vendor:publish --tag=laravel-assets --ansi --force', '@php artisan house:update --ansi', '@php artisan boost:update --ansi'])
        ->and(file_get_contents("{$repo}/package.json"))->toBe("{\n  \"scripts\": {\n    \"build\": \"vite build\",\n    \"check\": \"tsc\"\n  }\n}\n")
        ->and(file_get_contents("{$repo}/.gitignore"))->toBe("/vendor\n.env\n/.claude/settings.local.json\n.env.prod\n/frankenphp\n/public/frankenphp-worker.php\n");

    (new Process(['git', '-c', 'user.name=Acme', '-c', 'user.email=dev@acme.test', 'commit', '-q', '--allow-empty', '-m', 'init'], $repo))->mustRun();

    expect(fresh($repo, 'htmx')->getErrorOutput())->toContain('--fresh is for a fresh skeleton')
        ->and(fresh(skeleton(), 'htmx', ['--dry-run'])->getOutput())->toContain("would: deleted AGENTS.md\n")->not->toContain('skipped, already exists');
});

it('takes "scripts": [] in package.json', function () {
    $repo = skeleton();
    file_put_contents("{$repo}/package.json", "{\n  \"scripts\": []\n}\n");

    $run = fresh($repo, 'htmx');

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and(file_get_contents("{$repo}/package.json"))->toBe("{\n  \"scripts\": {\n    \"check\": \"tsc\"\n  }\n}\n");
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

function verifySetup(string $repo): Process
{
    $process = new Process(['php', Sandbox::package().'/resources/boost/skills/laravel-project-setup/scripts/verify.php', $repo]);
    $process->run();

    return $process;
}

it('verifies what setup leaves, one line per failure', function () {
    $repo = skeleton();
    fresh($repo, 'htmx,tenancy');
    $claude = str_replace('{{what_we_are_building}}', 'Acme Notes keeps notes.', file_get_contents("{$repo}/CLAUDE.md"));
    file_put_contents("{$repo}/CLAUDE.md", $claude."\n<laravel-boost-guidelines>\n# Laravel Boost\n".implode('', array_map(fn ($t) => "=== petar-spasic/laravel-house/{$t} rules ===\n", ['auth', 'core', 'frontend', 'tenancy']))."</laravel-boost-guidelines>\n");
    @mkdir("{$repo}/.claude/skills/testing-best-practices", 0775, true);
    copy(Sandbox::package().'/resources/boost/skills/laravel-project-setup/templates/core/.ai/skills/testing-best-practices/SKILL.md', "{$repo}/.claude/skills/testing-best-practices/SKILL.md");
    file_put_contents("{$repo}/phpunit.xml", "<phpunit><testsuites><testsuite name=\"E2E\"><directory>tests/E2E</directory></testsuite></testsuites></phpunit>\n");

    $clean = verifySetup($repo);

    expect($clean->getOutput())->toBe('')->and($clean->getExitCode())->toBe(0);

    file_put_contents("{$repo}/CLAUDE.md", str_replace('# Laravel Boost', "# Laravel Boost\nUse make:test", file_get_contents("{$repo}/CLAUDE.md")));
    file_put_contents("{$repo}/AGENTS.md", "# Agents\n");
    file_put_contents("{$repo}/app/Note.php", "<?php // {{app_name}}\n");
    file_put_contents("{$repo}/.env", "CACHE_STORE=file\n", FILE_APPEND);
    file_put_contents("{$repo}/phpunit.xml", "<phpunit><testsuites><testsuite name=\"Unit\"/><testsuite name=\"E2E\"/></testsuites></phpunit>\n");
    file_put_contents("{$repo}/routes/CLAUDE.md", "# Routes\n");
    file_put_contents("{$repo}/CLAUDE.md", str_replace("=== petar-spasic/laravel-house/tenancy rules ===\n", '', file_get_contents("{$repo}/CLAUDE.md")));
    // a section the block renders, kept above it, is named; a house heading the block does not render is the project's own
    file_put_contents("{$repo}/CLAUDE.md", str_replace(
        ['<laravel-boost-guidelines>', "=== petar-spasic/laravel-house/frontend rules ===\n"],
        ["## Non-negotiables — SPEED, SEO, SMOOTHNESS\n\nKept.\n\n## Frontend — SvelteKit\n\nOurs.\n\n<laravel-boost-guidelines>", "=== petar-spasic/laravel-house/frontend rules ===\n\n## Non-negotiables — SPEED, SEO, SMOOTHNESS\n"],
        file_get_contents("{$repo}/CLAUDE.md"),
    ));
    $broken = verifySetup($repo);

    expect($broken->getExitCode())->toBe(1)
        ->and($broken->getOutput())->toBe(implode("\n", [
            '✗ app/Note.php:1 unresolved template marker or placeholder',
            '✗ AGENTS.md is still there',
            '✗ .env still sets CACHE_STORE: the config defaults are Postgres and Redis',
            '✗ the house files differ from config/house.php: not managed: routes/CLAUDE.md has no house:begin and house:end markers; add them around the house text, or list it under overrides (php artisan house:update)',
            '✗ Boost\'s block in CLAUDE.md lacks the house tenancy rules: config/house.php, petar-spasic/laravel-house in boost.json packages, and `boost:update` naming it as a file it could not render',
            '✗ Boost\'s guidelines in CLAUDE.md still say "make:test": an override is missing (references/boost.md)',
            '✗ CLAUDE.md keeps the house section "Non-negotiables — SPEED, SEO, SMOOTHNESS" above Boost\'s block: delete it, or move a project rule into a section of its own (references/adopt.md, Rendered rules)',
            '✗ phpunit.xml has the test suites Unit, E2E; it has one, E2E',
        ])."\n");
});

it('names a package too old to render the house rules, and still reports the rest', function () {
    $repo = skeleton();
    fresh($repo, 'htmx');
    mkdir("{$repo}/vendor/petar-spasic/laravel-house", 0775, true);

    $verify = verifySetup($repo);

    expect($verify->getExitCode())->toBe(1)
        ->and($verify->getOutput())->toContain("/vendor/petar-spasic/laravel-house renders no house rules: an older petar-spasic/laravel-house (composer require --dev petar-spasic/laravel-house)\n")
        ->toContain('✗ CLAUDE.md does not end with the <laravel-boost-guidelines> block');
});

/** laravel-deployment's docker/verify.sh, rendered for $modules, against a fake docker and curl. */
function stackVerify(string $modules, array $env = [], string $dotenv = ''): Process
{
    $root = Sandbox::tmp();
    $repo = "{$root}/repo";
    $render = "{$root}/render";
    mkdir($repo);
    touch("{$repo}/artisan");
    $rendered = installPhp($repo, ['--templates='.Sandbox::package().'/resources/boost/skills/laravel-deployment/templates', "--modules={$modules}",
        '--set', 'app=acme', '--set', 'web_port=8000', "--render-to={$render}"]);
    expect($rendered->getExitCode())->toBe(0, $rendered->getErrorOutput());
    file_put_contents("{$render}/.env", "WEB_PORT=8011\n{$dotenv}");
    $bin = "{$root}/bin";
    mkdir($bin);
    file_put_contents("{$bin}/docker", <<<'SH'
        #!/usr/bin/env bash
        echo "$*" >> "$FAKE_LOG"
        case "$*" in
            *supervisorctl\ status*) for p in $FAKE_RUNNING; do echo "$p RUNNING pid 1, uptime 0:01:00"; done ;;
        esac
        exit 0
        SH);
    file_put_contents("{$bin}/curl", <<<'SH'
        #!/usr/bin/env bash
        url=${!#}; path=/${url#http://*/}
        echo "$url" >> "$FAKE_LOG"
        code=404
        case "$path" in /up|/kanban) code=200 ;; /kanban\?token=*) case " $* " in *" -L "*) code=200 ;; *) code=303 ;; esac ;; esac
        for pair in $FAKE_STATUS; do [ "${pair%%=*}" = "$path" ] && code=${pair#*=}; done
        case "$*" in *content_type*) echo -n "$code application/json" ;; *http_code*) echo -n "$code" ;; esac
        SH);
    chmod("{$bin}/docker", 0755);
    chmod("{$bin}/curl", 0755);
    $process = new Process(['bash', 'docker/verify.sh'], $render, $env + ['PATH' => "{$bin}:".getenv('PATH'), 'FAKE_LOG' => "{$root}/calls.log",
        'FAKE_RUNNING' => 'app:php-fpm app:caddy app:scheduler app:horizon app:reverb', 'FAKE_STATUS' => '']);
    $process->run();

    return $process;
}

it('probes the local stack with the deployment Verify steps a script can take', function () {
    $passed = stackVerify('reverb');

    expect($passed->getOutput())->toBe('')->and($passed->getExitCode())->toBe(0);

    $failed = stackVerify('reverb', ['FAKE_RUNNING' => 'app:php-fpm app:caddy app:scheduler app:horizon', 'FAKE_STATUS' => '/.env=200']);

    expect($failed->getExitCode())->toBe(1)
        ->and($failed->getOutput())->toBe("✗ /.env answers 200, not 404\n✗ supervisor program app:reverb is not RUNNING\n");
});

it('follows the board page\'s token redirect to the page', function () {
    $verify = stackVerify('', [], "KANBAN_UI_TOKEN=s3cret\n");
    $render = $verify->getWorkingDirectory();
    mkdir("{$render}/vendor/bin", 0755, true);
    file_put_contents("{$render}/vendor/bin/kanban", "#!/bin/sh\n");
    chmod("{$render}/vendor/bin/kanban", 0755);
    $verify->run();

    expect($verify->getOutput())->toBe('')->and($verify->getExitCode())->toBe(0)
        ->and(file_get_contents(dirname($render).'/calls.log'))->toContain('/kanban?token=s3cret');
});

it('reports a placeholder only in the files git tracks or would add, never in an ignored card clone', function () {
    $repo = skeleton();
    fresh($repo, 'htmx');
    file_put_contents("{$repo}/.gitignore", "/.claude/worktrees\n", FILE_APPEND);
    @mkdir("{$repo}/.claude/worktrees/x/app", 0775, true);
    file_put_contents("{$repo}/.claude/worktrees/x/app/Note.php", "<?php // {{app_name}}\n");
    file_put_contents("{$repo}/app/Note.php", "<?php // {{app_name}}\n");
    (new Process(['git', '-C', $repo, 'add', 'app/Note.php']))->mustRun();

    $verify = verifySetup($repo);

    expect($verify->getOutput())->toContain("✗ app/Note.php:1 unresolved template marker or placeholder\n")
        ->not->toContain('.claude/worktrees');
});

it("reports only the house's own placeholders, never an app's {{field}} templates", function () {
    $repo = skeleton();
    fresh($repo, 'htmx');
    file_put_contents("{$repo}/app/Note.php", "<?php // Dear {{contact}}, {{app_name}}\n");
    file_put_contents("{$repo}/app/Letter.php", "<?php // Dear {{contact}}, about {{order_number}}\n");

    $verify = verifySetup($repo);

    expect($verify->getOutput())->toContain("✗ app/Note.php:1 unresolved template marker or placeholder\n")
        ->not->toContain('app/Letter.php');
});

it('boots prod only with a whole number of Octane workers and keeps supervisor retrying through a Redis restart', function (string|false $workers, bool $boots) {
    $out = deployment('');
    $script = file_get_contents("{$out}/docker/docker-entrypoint.sh");
    $start = (int) strpos($script, 'case "${OCTANE_WORKERS');
    $check = substr($script, $start, strpos($script, "\nesac\n", $start) + 6 - $start);

    $run = new Process(['bash', '-c', "set -e\n{$check}echo booted"], $out, ['OCTANE_WORKERS' => $workers]);
    $run->run();

    expect($run->getExitCode())->toBe($boots ? 0 : 1)
        ->and(str_contains($run->getOutput(), 'booted'))->toBe($boots);
    foreach (['docker-entrypoint.sh', 'docker-entrypoint-local.sh'] as $entrypoint) {
        expect(file_get_contents("{$out}/docker/{$entrypoint}"))->toContain("\nstartsecs=5\nstartretries=20\n");
    }
})->with([
    'four' => ['4', true],
    'twelve' => ['12', true],
    'zero' => ['0', false],
    'double zero' => ['00', false],
    'leading zero' => ['04', false],
    'auto' => ['auto', false],
    'empty' => ['', false],
    'unset' => [false, false],
]);

it('runs the project\'s executable docker/e2e-reset.sh on the test env after the database reset', function () {
    $script = file_get_contents(deployment('spa').'/docker/e2e.sh');
    $start = (int) strpos($script, "\ntest_env php artisan cache:clear\n") + 1;
    $hook = substr($script, $start, strpos($script, "\ncd frontend\n") - $start);
    $dir = Sandbox::tmp();
    mkdir("{$dir}/docker");
    $run = function () use ($dir, $hook): Process {
        $process = new Process(['bash', '-c', "set -e\ntest_env() { [ \"\$1\" = php ] || env APP_E2E=true \"\$@\"; }\n{$hook}\necho done"], $dir);
        $process->run();

        return $process;
    };

    $none = $run();
    file_put_contents("{$dir}/docker/e2e-reset.sh", "#!/bin/bash\necho \"reset APP_E2E=\$APP_E2E\"\n");
    $notExecutable = $run();
    chmod("{$dir}/docker/e2e-reset.sh", 0755);
    $ran = $run();
    file_put_contents("{$dir}/docker/e2e-reset.sh", "#!/bin/bash\nexit 3\n");
    $failed = $run();

    expect($none->getExitCode())->toBe(0)->and($none->getOutput())->toBe("done\n")
        ->and($notExecutable->getExitCode())->toBe(1)
        ->and($notExecutable->getOutput())->toBe("docker/e2e-reset.sh is not executable: chmod +x it\n")
        ->and($ran->getOutput())->toBe("reset APP_E2E=true\ndone\n")
        ->and($failed->getExitCode())->toBe(3)->and($failed->getOutput())->toBe('');
});
