<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use Illuminate\Support\Facades\Artisan;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use RuntimeException;
use Symfony\Component\Process\Process;

/** A throwaway main checkout in the temp dir, driven through `bin/kanban` and git as a user would. */
final class Sandbox
{
    public function __construct(public readonly string $root) {}

    public static function create(string $name = 'main'): self
    {
        $root = self::tmp().'/'.$name;
        mkdir($root, 0775, true);
        $sandbox = new self($root);
        $sandbox->git('init', '-q', '-b', 'main');
        $sandbox->configure();
        file_put_contents($root.'/README.md', "# app\n");
        file_put_contents($root.'/.gitignore', "/vendor/\n");
        $sandbox->git('add', '-A');
        $sandbox->git('commit', '-q', '-m', 'init');
        $sandbox->vendorBin();

        return $sandbox;
    }

    /** A fresh temp directory, removed at shutdown. */
    public static function tmp(): string
    {
        $dir = sys_get_temp_dir().'/kanban-e2e-'.bin2hex(random_bytes(5));
        mkdir($dir, 0775, true);
        register_shutdown_function(fn () => (new Process(['rm', '-rf', $dir]))->run());

        return $dir;
    }

    public static function package(): string
    {
        return dirname(__DIR__, 2);
    }

    /** `php artisan kanban:install` against this checkout (in-process artisan, Paths rebound here). */
    public function install(string $key = 'ACME', array $options = []): string
    {
        app()->instance(Paths::class, Paths::discover($this->root));
        $code = Artisan::call('kanban:install', ['--key' => $key, ...$options]);
        $output = Artisan::output();
        if ($code !== 0) {
            throw new RuntimeException("kanban:install exited {$code}: {$output}");
        }

        return $output;
    }

    /**
     * Runs `bin/kanban …` with this checkout (or $cwd) as the working directory.
     *
     * @param  list<string>|string  $args
     * @param  array<string, string|false>  $env
     */
    public function kanban(array|string $args, array $env = [], ?string $cwd = null, ?string $input = null): Process
    {
        $process = new Process([PHP_BINARY, self::package().'/bin/kanban', ...(array) $args], $cwd ?? $this->root, $this->env($env), $input, 120);
        $process->run();

        return $process;
    }

    /** Starts `bin/kanban …` without waiting. */
    public function start(array $args, array $env = [], ?string $input = null): Process
    {
        $process = new Process([PHP_BINARY, self::package().'/bin/kanban', ...$args], $this->root, $this->env($env), $input, 120);
        $process->start();

        return $process;
    }

    /** Runs `bin/kanban …` and fails loudly unless it exits 0; returns stdout. */
    public function ok(array|string $args, array $env = [], ?string $input = null): string
    {
        $process = $this->kanban($args, $env, null, $input);
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('kanban '.implode(' ', (array) $args).' exited '.$process->getExitCode().":\n".$process->getOutput().$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /** Creates a card and returns its id. */
    public function card(string $title, array $options = [], string $board = 'project/work', array $env = []): string
    {
        $out = $this->ok(['new', $board, $title, ...$options], $env);
        if (preg_match('/^created (\S+)/m', $out, $m) !== 1) {
            throw new RuntimeException("no id in: {$out}");
        }

        return $m[1];
    }

    /** A card that passes the ready policy, created straight into ready; on an area of its own unless $options name one. */
    public function readyCard(string $title, array $options = [], string $board = 'project/work', array $env = []): string
    {
        return $this->card($title, ['--body=Build it', '--accept=It works', '--stage=ready', ...self::withArea($options)], $board, $env);
    }

    /**
     * $options plus a fresh `area:*` label when they carry none, so cards made apart never share an area.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    public static function withArea(array $options): array
    {
        static $areas = 0;
        foreach ($options as $option) {
            if (str_starts_with($option, '--label=area:')) {
                return $options;
            }
        }

        return [...$options, '--label=area:a'.++$areas];
    }

    /** @return array<string, mixed> */
    public function read(string $id): array
    {
        $files = glob($this->root.'/docs/kanban/*/*/'.$id.'.json');
        if (count($files) !== 1) {
            throw new RuntimeException("card file {$id}: ".count($files).' matches');
        }

        return json_decode((string) file_get_contents($files[0]), true, 64, JSON_THROW_ON_ERROR);
    }

    public function git(string ...$args): string
    {
        $process = new Process(['git', ...$args], $this->root, ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C']);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $args).': '.$process->getErrorOutput().$process->getOutput());
        }

        return $process->getOutput();
    }

    public function boardGit(string ...$args): string
    {
        return $this->git('-C', 'docs/kanban', ...$args);
    }

    /** Commit subjects on the kanban branch, newest first. */
    public function boardLog(): array
    {
        return array_values(array_filter(explode("\n", $this->git('log', '--format=%s', 'kanban'))));
    }

    public function addRemote(Origin $origin): self
    {
        $this->git('remote', 'add', 'origin', $origin->path);
        $this->git('push', '-q', '-u', 'origin', 'main');

        return $this;
    }

    public function configure(): void
    {
        $this->git('config', 'user.name', 'Test User');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    /** `vendor/bin/kanban` like composer's proxy: sets the autoload path, then runs the package binary; `kanban-exec` linked. */
    public function vendorBin(): void
    {
        @mkdir($this->root.'/vendor/bin', 0775, true);
        $package = self::package();
        file_put_contents($this->root.'/vendor/bin/kanban', <<<PHP
            #!/usr/bin/env php
            <?php
            \$GLOBALS['_composer_autoload_path'] = '{$package}/vendor/autoload.php';
            eval('?>'.preg_replace('/^#!.*\\n/', '', file_get_contents('{$package}/bin/kanban')));
            PHP);
        chmod($this->root.'/vendor/bin/kanban', 0755);
        @symlink($package.'/bin/kanban-exec', $this->root.'/vendor/bin/kanban-exec');
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array<string, string|false>
     */
    private function env(array $env): array
    {
        return $env + ['KANBAN_SESSION' => false, 'KANBAN_SYNC' => 'off', 'KANBAN_USER' => false, 'KANBAN_GIT_AUTHOR' => false, 'KANBAN_ID_SEQUENCE' => false, 'KANBAN_UPSTREAM' => false, 'KANBAN_GIT_TOKEN' => false, 'XDEBUG_MODE' => 'off'];
    }
}
