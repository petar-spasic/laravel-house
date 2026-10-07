<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Support\RenderFailures;
use PetarSpasic\LaravelHouse\Setup\HouseConfig;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

/** A repo the setup templates were installed into with $modules. */
function houseRepo(string $modules = 'htmx,tenancy'): string
{
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    $install = installPhp($repo, ["--modules={$modules}", '--set', 'app=acme', '--set', 'laravel_version=13',
        '--set', 'php_version=8.5', '--set', 'pest_version=5']);
    expect($install->getExitCode())->toBe(0, $install->getErrorOutput());

    return $repo;
}

/**
 * The house guidelines as `boost:update` writes them into a project's root CLAUDE.md, by topic, and the files Boost
 * could not render. The project lists the package in composer.lock and boost.json, and `$house` is its config/house.php
 * (none when null). Boost runs only outside unit tests and writes CLAUDE.md in the working directory, so the app turns
 * local and the test works in the project.
 *
 * @return array{array<string, string>, list<string>} topic => its rendered rules, and the failed files
 */
function boostUpdate(?array $house): array
{
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    mkdir("{$repo}/vendor/petar-spasic", 0775, true);
    symlink(Sandbox::package(), "{$repo}/vendor/petar-spasic/laravel-house");
    file_put_contents("{$repo}/composer.json", json_encode(['require-dev' => ['petar-spasic/laravel-house' => '*']]));
    file_put_contents("{$repo}/composer.lock", json_encode(['packages' => [], 'packages-dev' => [['name' => 'petar-spasic/laravel-house', 'version' => 'dev-main']]]));
    file_put_contents("{$repo}/boost.json", json_encode(['agents' => ['claude_code'], 'guidelines' => true, 'packages' => ['petar-spasic/laravel-house']]));
    if ($house !== null) {
        mkdir("{$repo}/config");
        file_put_contents("{$repo}/config/house.php", '<?php return '.var_export($house, true).';');
    }
    config(['boost.agents.claude_code.guidelines_path' => 'CLAUDE.md']);
    app()['env'] = 'local';
    app()->setBasePath($repo);
    app()->register(BoostServiceProvider::class);
    $cwd = getcwd();
    chdir($repo);
    try {
        expect(Artisan::call('boost:update'))->toBe(0);
        $failed = app(RenderFailures::class)->paths();
    } finally {
        chdir($cwd);
    }
    $parts = preg_split('/^=== (\S+) rules ===$/m', (string) file_get_contents("{$repo}/CLAUDE.md"), -1, PREG_SPLIT_DELIM_CAPTURE);
    $topics = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        if (str_starts_with($parts[$i], 'petar-spasic/laravel-house/')) {
            $topics[substr($parts[$i], strlen('petar-spasic/laravel-house/'))] = trim(preg_replace('#</laravel-boost-guidelines>\s*$#', '', $parts[$i + 1]));
        }
    }

    return [$topics, $failed];
}

/** @return array<string, string> the topics of a render Boost completed without a failure */
function houseGuidelines(?array $house): array
{
    [$topics, $failed] = boostUpdate($house);
    expect($failed)->toBe([]);

    return $topics;
}

const HOUSE_TOPICS = ['auth', 'core', 'frontend', 'tenancy'];

it('records the modules and values in config/house.php, and the update renders nothing new on a fresh install', function () {
    $repo = houseRepo('htmx,islands,reverb');

    $house = require "{$repo}/config/house.php";
    $update = installPhp($repo, ['--update', '--check']);

    expect($house)->toBe(['app' => 'acme', 'laravel_version' => '13', 'php_version' => '8.5', 'pest_version' => '5',
        'modules' => ['htmx', 'islands', 'reverb'], 'overrides' => []])
        ->and(file_get_contents("{$repo}/app/Models/CLAUDE.md"))->toStartWith('<!-- house:begin')->toEndWith("<!-- house:end -->\n")
        ->and(file_get_contents("{$repo}/CLAUDE.md"))->not->toContain('house:begin')->toContain('{{what_we_are_building}}')
        ->and($update->getOutput())->toBe('')
        ->and($update->getExitCode())->toBe(0);
});

it('renders the house span and the .ai files again, keeps the project\'s text, an override and a file without markers', function () {
    $repo = houseRepo();
    $models = "{$repo}/app/Models/CLAUDE.md";
    file_put_contents($models, str_replace('Fat models', 'FAT models', file_get_contents($models))."\n## Acme\n\n- Notes are never deleted.\n");
    file_put_contents("{$repo}/.ai/guidelines/pest/core.blade.php", "stale\n");
    file_put_contents("{$repo}/database/CLAUDE.md", "# Our own database rules\n");
    file_put_contents("{$repo}/routes/CLAUDE.md", "# Routes, kept\n");
    unlink("{$repo}/tests/CLAUDE.md");
    file_put_contents("{$repo}/config/house.php", str_replace("'overrides' => []", "'overrides' => ['routes/CLAUDE.md']", file_get_contents("{$repo}/config/house.php")));

    $check = installPhp($repo, ['--update', '--check']);
    $untouched = file_get_contents($models);
    $update = installPhp($repo, ['--update']);

    expect($check->getExitCode())->toBe(1)
        ->and($check->getOutput())->toBe(implode("\n", [
            'would update .ai/guidelines/pest/core.blade.php',
            'would update app/Models/CLAUDE.md',
            'not managed: database/CLAUDE.md has no house:begin and house:end markers; add them around the house text, or list it under overrides',
            'would create tests/CLAUDE.md',
        ])."\n")
        ->and($untouched)->toContain('FAT models')
        ->and($update->getExitCode())->toBe(0)
        ->and($update->getOutput())->toContain("update app/Models/CLAUDE.md\n")->toContain("create tests/CLAUDE.md\n")
        ->and(file_get_contents($models))->toContain('Fat models')->not->toContain('FAT models')
        ->toEndWith("<!-- house:end -->\n\n## Acme\n\n- Notes are never deleted.\n")
        ->and(file_get_contents("{$repo}/.ai/guidelines/pest/core.blade.php"))->not->toBe("stale\n")
        ->and(file_get_contents("{$repo}/database/CLAUDE.md"))->toBe("# Our own database rules\n")
        ->and(file_get_contents("{$repo}/routes/CLAUDE.md"))->toBe("# Routes, kept\n")
        ->and(installPhp($repo, ['--update', '--check'])->getOutput())->toStartWith('not managed: database/CLAUDE.md');
});

it('renders the module text a changed module set asks for', function () {
    $repo = houseRepo('htmx');
    file_put_contents("{$repo}/config/house.php", str_replace("'modules' => ['htmx']", "'modules' => ['htmx', 'tenancy']", file_get_contents("{$repo}/config/house.php")));

    $update = installPhp($repo, ['--update']);

    expect($update->getExitCode())->toBe(0)
        ->and($update->getOutput())->toContain("update app/Models/CLAUDE.md\n")
        ->and(file_get_contents("{$repo}/app/Models/CLAUDE.md"))->toMatch('/tenant/i');
});

it('refuses an update without config/house.php, or with modules or values of its own', function () {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");

    $missing = installPhp($repo, ['--update']);
    $extra = installPhp(houseRepo(), ['--update', '--modules=spa']);
    $check = installPhp(houseRepo(), ['--check']);

    expect($missing->getExitCode())->toBe(1)
        ->and($missing->getErrorOutput())->toContain('no config/house.php: this project is not on the rendered house rules yet')
        ->and($extra->getErrorOutput())->toContain('--update takes only --check')
        ->and($check->getErrorOutput())->toContain('--check goes with --update');
});

it('refuses a module set the rules cannot render, and a missing value', function () {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");

    $pages = installPhp($repo, ['--modules=auth-pages', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5']);
    $version = installPhp($repo, ['--modules=htmx', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5']);
    $lacking = houseRepo();
    file_put_contents("{$lacking}/config/house.php", preg_replace("/^    'pest_version' => '5',\n/m", '', file_get_contents("{$lacking}/config/house.php")));

    $atSetup = installPhp($repo, ['--modules=htmx,auth-pages', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5']);

    expect($pages->getErrorOutput())->toContain('auth-pages requires htmx')
        ->and($atSetup->getErrorOutput())->toContain('auth-pages is the state of built auth pages, never set at setup')
        ->and($version->getExitCode())->toBe(1)
        ->and($version->getErrorOutput())->toContain('--set pest_version=… is required')
        ->and(installPhp($lacking, ['--update'])->getErrorOutput())->toContain('config/house.php: pest_version is missing');
});

it('turns the htmx auth rules to views on with auth-pages', function () {
    $repo = houseRepo('htmx');
    file_put_contents("{$repo}/config/house.php", str_replace("'modules' => ['htmx']", "'modules' => ['htmx', 'auth-pages']", file_get_contents("{$repo}/config/house.php")));

    $update = installPhp($repo, ['--update']);

    expect($update->getOutput())->toContain("update routes/CLAUDE.md\n")->toContain("update resources/CLAUDE.md\n")
        ->and(file_get_contents("{$repo}/routes/CLAUDE.md"))->toContain('**`views` is on**')->not->toContain('`views` is off')
        ->and(file_get_contents("{$repo}/app/Http/CLAUDE.md"))->not->toContain('AcceptJson` on Fortify')
        ->and(file_get_contents("{$repo}/resources/CLAUDE.md"))->toContain("Fortify's views are on")->not->toContain('The views flip');
});

it('names the house files of a module that is off', function () {
    $repo = houseRepo('htmx');
    file_put_contents("{$repo}/config/house.php", str_replace("'modules' => ['htmx']", "'modules' => ['spa']", file_get_contents("{$repo}/config/house.php")));

    $check = installPhp($repo, ['--update', '--check']);
    $update = installPhp($repo, ['--update']);

    expect($check->getExitCode())->toBe(1)
        ->and($check->getOutput())->toContain("would create frontend/CLAUDE.md\n")
        ->toContain("remove by hand resources/CLAUDE.md: module htmx is off (keep any rule of this project's own elsewhere, or list it under overrides)\n")
        ->and($update->getOutput())->toContain('remove by hand resources/CLAUDE.md')
        ->and(file_exists("{$repo}/resources/CLAUDE.md"))->toBeTrue();
});

it('refuses to install other modules or values over a recorded config/house.php', function () {
    $repo = houseRepo('htmx');
    $sets = ['--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5'];

    $modules = installPhp($repo, ['--modules=htmx,tenancy', ...$sets, '--force']);
    $values = installPhp($repo, ['--modules=htmx', '--set', 'app=acme', '--set', 'laravel_version=12', '--set', 'php_version=8.5', '--set', 'pest_version=5']);
    $same = installPhp($repo, ['--modules=htmx', ...$sets]);

    expect($modules->getExitCode())->toBe(1)
        ->and($modules->getErrorOutput())->toContain('config/house.php records other modules or values: change them there and run `php artisan house:update`')
        ->and(file_get_contents("{$repo}/app/Models/CLAUDE.md"))->not->toMatch('/tenant/i')
        ->and($values->getExitCode())->toBe(1)
        ->and($same->getExitCode())->toBe(0, $same->getErrorOutput());
});

it('lets an in-place run name the recorded auth-pages, and compares values exactly', function () {
    $repo = houseRepo('htmx');
    file_put_contents("{$repo}/config/house.php", str_replace("'modules' => ['htmx']", "'modules' => ['htmx', 'auth-pages']", file_get_contents("{$repo}/config/house.php")));
    $sets = ['--set', 'app=acme', '--set', 'php_version=8.5', '--set', 'pest_version=5'];

    $pages = installPhp($repo, ['--modules=htmx,auth-pages', ...$sets, '--set', 'laravel_version=13', '--dry-run']);
    $loose = installPhp($repo, ['--modules=htmx,auth-pages', ...$sets, '--set', 'laravel_version=13.0', '--dry-run']);

    expect($pages->getExitCode())->toBe(0, $pages->getErrorOutput())
        ->and($loose->getErrorOutput())->toContain('config/house.php records other modules or values');
});

it('names a config/house.php it cannot read, or whose app is no slug', function () {
    $repo = houseRepo('htmx');
    $config = file_get_contents("{$repo}/config/house.php");
    file_put_contents("{$repo}/config/house.php", str_replace("'php_version' => '8.5'", "'php_version' => env('PHP', '8.5')", $config));
    $env = installPhp($repo, ['--update']);
    file_put_contents("{$repo}/config/house.php", str_replace("'app' => 'acme'", "'app' => 'Acme-Notes'", $config));
    $slug = installPhp($repo, ['--update']);

    expect($env->getExitCode())->toBe(1)
        ->and($env->getErrorOutput())->toContain('config/house.php cannot be read: Call to undefined function env() (plain values only)')
        ->and($slug->getErrorOutput())->toContain('config/house.php: app must be lowercase letters and digits');
});

it('keeps the project\'s own house:update line, flags and all', function () {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    file_put_contents("{$repo}/composer.json", json_encode(['scripts' => ['post-update-cmd' => ['@php artisan boost:update', '@php artisan house:update -v']]]));

    installPhp($repo, ['--adopt', '--modules=htmx', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5']);

    expect(json_decode(file_get_contents("{$repo}/composer.json"), true)['scripts']['post-update-cmd'])
        ->toBe(['@php artisan house:update -v', '@php artisan boost:update']);
});

it('fills every placeholder of a house file from config/house.php', function () {
    $root = Sandbox::package().'/resources/boost/skills/laravel-project-setup/templates';
    $left = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $text = (string) file_get_contents((string) $file);
        $relative = substr((string) $file, strlen($root) + 1);
        if ((str_contains($relative, '/.ai/') || str_contains($text, '<!-- house:begin')) && preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $m)) {
            $left = [...$left, ...array_diff($m[1], HouseConfig::VARS)];
        }
    }

    expect(array_values(array_unique($left)))->toBe([]);
});

it('never overwrites config/house.php, --force included', function () {
    $repo = houseRepo('htmx');
    $house = str_replace("'overrides' => []", "'overrides' => ['routes/CLAUDE.md']", file_get_contents("{$repo}/config/house.php"));
    file_put_contents("{$repo}/config/house.php", $house);

    $force = installPhp($repo, ['--modules=htmx', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5', '--force']);

    expect($force->getExitCode())->toBe(0)
        ->and($force->getOutput())->toContain("skipped, already exists (1):\n  config/house.php\n")
        ->and(file_get_contents("{$repo}/config/house.php"))->toBe($house);
});

it('keeps a checkout\'s CRLF line endings', function () {
    $repo = houseRepo('htmx');
    $models = "{$repo}/app/Models/CLAUDE.md";
    $crlf = str_replace("\n", "\r\n", file_get_contents($models));
    file_put_contents($models, $crlf);

    $pest = "{$repo}/.ai/guidelines/pest/core.blade.php";
    file_put_contents($pest, str_replace("\n", "\r\n", file_get_contents($pest)));
    $clean = installPhp($repo, ['--update', '--check']);
    file_put_contents($models, str_replace('Fat models', 'FAT models', $crlf)."## Acme\r\n");
    $update = installPhp($repo, ['--update']);

    expect($clean->getOutput())->toBe('')
        ->and($update->getOutput())->toBe("update app/Models/CLAUDE.md\n")
        ->and(file_get_contents($models))->toBe($crlf."## Acme\r\n");
});

it('adopts a project: config/house.php and the composer script order, nothing else', function () {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    file_put_contents("{$repo}/composer.json", "{\n\t\"name\": \"acme/notes\",\n\t\"require-dev\": {},\n\t\"scripts\": {\n\t\t\"post-update-cmd\": [\n"
        ."\t\t\t\"@php artisan boost:update\",\n\t\t\t\"@php artisan vendor:publish --tag=laravel-assets --ansi --force\"\n\t\t]\n\t}\n}\n");
    $args = ['--adopt', '--modules=htmx,auth-pages', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5'];

    $adopt = installPhp($repo, $args);
    $again = installPhp($repo, $args);

    expect($adopt->getExitCode())->toBe(0, $adopt->getErrorOutput())
        ->and($adopt->getOutput())->toBe("adopt: wrote config/house.php\nadopt: composer.json post-update-cmd ends with house:update, then boost:update\n")
        ->and((require "{$repo}/config/house.php")['modules'])->toBe(['htmx', 'auth-pages'])
        ->and(file_get_contents("{$repo}/composer.json"))->toBe("{\n\t\"name\": \"acme/notes\",\n\t\"require-dev\": {},\n\t\"scripts\": {\n\t\t\"post-update-cmd\": [\n"
            ."\t\t\t\"@php artisan vendor:publish --tag=laravel-assets --ansi --force\",\n\t\t\t\"@php artisan house:update --ansi\",\n\t\t\t\"@php artisan boost:update\"\n\t\t]\n\t}\n}\n")
        ->and(array_values(array_diff(scandir($repo), ['.', '..'])))->toBe(['artisan', 'composer.json', 'config'])
        ->and($again->getOutput())->toBe("adopt: config/house.php exists: kept\n")
        ->and(installPhp($repo, ['--adopt', '--update'])->getErrorOutput())->toContain('--adopt takes only --modules, --set and --dry-run');
});

it('says how to leave htmx with auth-pages on', function () {
    $repo = houseRepo('htmx');
    file_put_contents("{$repo}/config/house.php", str_replace("'modules' => ['htmx']", "'modules' => ['spa', 'auth-pages']", file_get_contents("{$repo}/config/house.php")));

    expect(installPhp($repo, ['--update'])->getErrorOutput())->toContain('config/house.php: auth-pages requires htmx: drop it from the modules when htmx goes');
});

it('refuses a value that is not plain, and an override that names no house file; reads ./ and / paths as the root', function () {
    $repo = houseRepo('htmx');
    $config = file_get_contents("{$repo}/config/house.php");
    file_put_contents("{$repo}/config/house.php", str_replace("'php_version' => '8.5'", "'php_version' => ['8.5']", $config));
    $array = installPhp($repo, ['--update']);
    file_put_contents("{$repo}/config/house.php", str_replace("'php_version' => '8.5'", "'php_version' => 8.10", $config));
    $float = installPhp($repo, ['--update']);
    file_put_contents("{$repo}/config/house.php", str_replace("'overrides' => []", "'overrides' => ['CLAUDE.md']", $config));
    $root = installPhp($repo, ['--update']);
    file_put_contents("{$repo}/config/house.php", str_replace("'overrides' => []", "'overrides' => ['routes/CLAUDE.MD']", $config));
    $unknown = installPhp($repo, ['--update']);
    file_put_contents("{$repo}/config/house.php", str_replace("'overrides' => []", "'overrides' => ['./routes/CLAUDE.md', '/.ai/guidelines/pest/core.blade.php']", $config));
    file_put_contents("{$repo}/routes/CLAUDE.md", "# Routes, ours\n");
    file_put_contents("{$repo}/.ai/guidelines/pest/core.blade.php", "ours\n");

    expect($array->getErrorOutput())->toContain('config/house.php: php_version must be a quoted string')
        ->and($float->getErrorOutput())->toContain('config/house.php: php_version must be a quoted string')
        ->and($unknown->getErrorOutput())->toContain('config/house.php overrides names no file house:update writes: routes/CLAUDE.MD')
        ->and($root->getErrorOutput())->toContain('overrides names no file house:update writes: CLAUDE.md (a root rule is overridden by .ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php)')
        ->and(installPhp($repo, ['--update', '--check'])->getOutput())->toBe('');
});

it('adopts nothing when composer.json cannot take the scripts, and takes "scripts": []', function () {
    $repo = Sandbox::tmp();
    touch("{$repo}/artisan");
    $args = ['--adopt', '--modules=htmx', '--set', 'app=acme', '--set', 'laravel_version=13', '--set', 'php_version=8.5', '--set', 'pest_version=5'];
    file_put_contents("{$repo}/composer.json", 'not json');

    $broken = installPhp($repo, $args);
    $brokenLeft = file_exists("{$repo}/config/house.php");
    file_put_contents("{$repo}/composer.json", "{\n    \"scripts\": []\n}\n");
    $empty = installPhp($repo, $args);

    expect($broken->getExitCode())->toBe(1)
        ->and($broken->getErrorOutput())->toContain('composer.json is not a JSON object')
        ->and($brokenLeft)->toBeFalse()
        ->and($empty->getExitCode())->toBe(0, $empty->getErrorOutput())
        ->and(json_decode(file_get_contents("{$repo}/composer.json"), true))
        ->toBe(['scripts' => ['post-update-cmd' => ['@php artisan house:update --ansi', '@php artisan boost:update --ansi']]]);
});

it('fails the root rules\' render on a module set install.php refuses, as boost:update reports', function () {
    [$topics, $failed] = boostUpdate(['app' => 'acme', 'laravel_version' => '13', 'php_version' => '8.5', 'pest_version' => '5', 'modules' => ['spa', 'htmx']]);

    expect($failed)->not->toBe([])
        ->and(array_keys($topics))->not->toContain('core');
});

it('runs the update as house:update', function () {
    $repo = houseRepo();
    file_put_contents("{$repo}/.ai/guidelines/pest/core.blade.php", "stale\n");
    $this->app->setBasePath($repo);

    $check = Artisan::call('house:update', ['--check' => true]);
    $checked = Artisan::output();
    $update = Artisan::call('house:update');

    expect($check)->toBe(1)
        ->and($checked)->toBe("would update .ai/guidelines/pest/core.blade.php\n")
        ->and($update)->toBe(0)
        ->and(Artisan::call('house:update', ['--check' => true]))->toBe(0);
});

it('renders no house guideline in a project without config/house.php', function () {
    expect(houseGuidelines(null))->toBe([]);
});

it('renders each module\'s guideline text exactly when the module is on', function (array $modules) {
    $on = fn (string $module) => in_array($module, $modules, true);
    $topics = houseGuidelines(['app' => 'acme', 'laravel_version' => '13', 'php_version' => '8.5', 'pest_version' => '5', 'modules' => $modules])
        + array_fill_keys(HOUSE_TOPICS, '');
    $text = implode("\n", $topics);

    expect($topics['core'])->toStartWith('## TESTS ARE END TO END OR NOT AT ALL')->toContain('**Laravel 13**, PHP 8.5')->toContain('`acme_test`')
        ->and($topics['auth'])->toStartWith('## Auth')
        ->and($text)->not->toMatch('/\{\{|\}\}|@(end|unless)?houserules|___/')
        ->and($topics['tenancy'] !== '')->toBe($on('tenancy'))
        ->and((bool) preg_match('/tenan(t|cy)/i', $text))->toBe($on('tenancy'))
        ->and((bool) preg_match('/sveltekit|adapter-node|\/api\/auth/i', $text))->toBe($on('spa'))
        ->and(str_contains($text, 'Blade + htmx'))->toBe($on('htmx'))
        ->and(str_contains($text, 'Svelte 5 islands'))->toBe($on('islands'))
        ->and(str_contains($text, '`laravel/reverb`'))->toBe($on('reverb'))
        ->and(str_contains($topics['auth'], 'Its views are on'))->toBe($on('auth-pages'))
        ->and(str_contains($topics['auth'], 'Its views stay off'))->toBe($on('htmx') && ! $on('auth-pages'))
        ->and($topics['frontend'] === '')->toBe(! $on('htmx') && ! $on('spa'));
})->with(function () {
    foreach (['', 'htmx', 'htmx,islands', 'htmx,auth-pages', 'spa'] as $frontend) {
        foreach (['', 'reverb'] as $reverb) {
            foreach (['', 'tenancy'] as $tenancy) {
                $modules = array_values(array_filter([...explode(',', $frontend), $reverb, $tenancy]));
                yield implode(',', $modules) ?: 'api-only' => [$modules];
            }
        }
    }
});
