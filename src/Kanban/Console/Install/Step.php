<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use PetarSpasic\LaravelHouse\Kanban\Console\InstallCommand;
use PetarSpasic\LaravelHouse\Kanban\Console\InstallStep;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** An idempotent Claude Code / project wiring step of `kanban:install`, re-run by `doctor --fix` and checked by `doctor`. */
abstract class Step implements InstallStep
{
    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        protected readonly Paths $paths,
        protected readonly array $config = [],
    ) {}

    public function install(InstallCommand $command, bool $dryRun, bool $force): array
    {
        return $this->run($dryRun, $force);
    }

    /** @return list<string> one fact per line */
    abstract public function run(bool $dryRun = false, bool $force = false): array;

    /** @return list<array{0: 'ok'|'warn'|'fail', 1: string}> */
    abstract public function check(): array;

    public static function stub(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/stubs/'.$relative);
    }

    protected function path(string $relative): string
    {
        return $this->paths->main.'/'.$relative;
    }

    protected function read(string $relative): ?string
    {
        $file = $this->path($relative);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    protected function write(string $relative, string $content): void
    {
        $file = $this->path($relative);
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        $tmp = $file.'.kanban-tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $file);
    }

    /** Pretty JSON indented like $like (2 spaces when unknown), trailing newline. */
    protected static function json(mixed $data, ?string $like = null): string
    {
        $indent = $like !== null && preg_match('/^( +)\S/m', $like, $m) ? strlen($m[1]) : 2;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^(?: {4})+/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[0]), 4) * $indent), $json)."\n";
    }
}
