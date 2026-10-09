<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Console\Standalone;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The main checkout of this machine following main: fast-forwarded, then what the new commits need in it and in its stack
 * (installs, a rebuild, migrate, `finish.after`). A failure is a fault line and the next step runs; it never holds the
 * merge queue.
 */
final class MainCheckout
{
    /** What every fault line of this class starts with: a caller tells them apart from its own failure. */
    public const FAULT = '/^(main checkout not moved|warning: |install: |after: |rebuild main\b)/';

    private readonly Git $git;

    /**
     * @param  array<string, mixed>  $config  the `kanban` config
     * @param  Closure(string): void  $say
     * @param  Closure(string): void  $fault
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private readonly Closure $say,
        private readonly Closure $fault,
        private readonly bool $rebuild = true,
    ) {
        $this->git = new Git($paths->main);
    }

    /** Fast-forwards the main checkout to $to and runs the after-steps; false when a step failed (each one named). */
    public function follow(string $to): bool
    {
        $worktrees = new Worktrees($this->paths, $this->config);
        $sha7 = substr($to, 0, 7);
        if (($why = $worktrees->notOnMain()) !== null) {
            ($this->fault)("main checkout not moved: {$why}; run `git merge --ff-only {$sha7}`");

            return false;
        }
        $old = $worktrees->head('HEAD');
        if ($old === $to) {
            return true;
        }
        if (! $this->git->attempt(['merge-base', '--is-ancestor', $old, $to])->ok()) {
            ($this->fault)("main checkout not moved to {$sha7}: ".($this->git->attempt(['merge-base', '--is-ancestor', $to, $old])->ok()
                ? "it has commits the remote's main lacks; `kanban publish` pushes them, or drop them"
                : "it and the remote's main diverged; merge the remote's main into it by hand, then `kanban publish`"));

            return false;
        }
        $merge = $this->git->attempt(['merge', '--ff-only', '-q', $to]);
        if (! $merge->ok()) {
            ($this->fault)("main checkout not moved to {$sha7}: ".Worktrees::tail($merge->err ?: $merge->out).'; commit or stash the changes in the way, then `git merge --ff-only '.$sha7.'`');

            return false;
        }
        ($this->say)("main checkout at {$sha7}");

        return $this->after($old, $to);
    }

    /** What the commits from $from to $to need in the main checkout and its stack, in order; false when a step failed. */
    public function after(string $from, string $to): bool
    {
        $files = array_values(array_filter(explode("\n", trim($this->git->attempt(['diff', '--name-only', $from, $to])->out))));
        // before the steps, which may write files of their own
        $ok = $this->guard('warning', function () {
            $untracked = (new MergeCheck($this->git))->untracked();
            if ($untracked !== []) {
                ($this->say)('warning: main has untracked files (an agent\'s leftovers?): '.implode(', ', array_slice($untracked, 0, 10))
                    .(count($untracked) > 10 ? ' … '.(count($untracked) - 10).' more' : '').'; remove or commit them');
            }

            return true;
        });
        // the image the steps run in is the new one
        $installed = $this->guard('install', fn () => $this->install($files), 'after: skipped, the install step failed; fix it and run the rest by hand');
        $ok = $this->guard('rebuild main', fn () => $this->rebuildMain($files)) && $installed && $ok;
        if ($installed) {
            $ok = $this->guard('after', fn () => ! Stack::enabled((array) ($this->config['stack'] ?? []), $this->paths->main) || $this->databaseSteps()) && $ok;
        }

        return $ok;
    }

    /** A step: what it throws is printed as its failure, so the steps after it still run. */
    private function guard(string $kind, Closure $step, ?string $then = null): bool
    {
        try {
            return (bool) $step();
        } catch (Throwable $e) {
            ($this->fault)("{$kind}: ".basename(str_replace('\\', '/', $e::class)).': '.Worktrees::tail($e->getMessage()));
            $then === null || ($this->fault)($then);

            return false;
        }
    }

    /** What a changed lockfile needs, on this machine in its directory. A failed install skips the installs after it, then migrate and `finish.after`. @param  list<string>  $files */
    private function install(array $files): bool
    {
        foreach ($this->installs($files, (array) $this->finish()['install']) as [$dir, $command]) {
            if (! $this->step('install', $command, $dir)) {
                ($this->fault)("after: skipped, `{$command}` failed; fix it and run the rest by hand");

                return false;
            }
        }

        return true;
    }

    /** `migrate` and `finish.after` in main's stack, or in the checkout when `.env` names no compose project. */
    private function databaseSteps(): bool
    {
        $main = $this->paths->main;
        $commands = DatabaseSteps::commands(Standalone::config($main), $main);
        $stack = $this->mainStack();
        if ($commands === []) {
            return true;
        }
        if ($stack !== null && ($up = $stack->compose(['up', '-d', '--wait', '--no-recreate'], 600))['code'] !== 0) {
            ($this->fault)("after: skipped, main's stack {$stack->project} did not come up: ".Worktrees::tail($up['err'] ?: "exit {$up['code']}"));

            return false;
        }
        $ok = true;
        foreach ($commands as $command) {
            $ok = $this->step('after', $command, $main, $stack) && $ok;
        }

        return $ok;
    }

    private function mainStack(): ?Stack
    {
        $project = DotEnv::parse($this->paths->main.'/.env')['COMPOSE_PROJECT_NAME'] ?? '';

        return $project === '' ? null : new Stack($this->paths->main, $project, (array) ($this->config['stack'] ?? []), $this->paths->main);
    }

    /**
     * `finish` as main now states it, so a step a merged card adds runs at once. A project's `finish` replaces the
     * package's whole, so each key it leaves out keeps the package default.
     *
     * @return array<string, mixed>
     */
    private function finish(): array
    {
        $package = require dirname(__DIR__, 3).'/config/kanban.php';

        return (array) (Standalone::config($this->paths->main)['finish'] ?? []) + $package['finish'];
    }

    /**
     * The install command of each changed lockfile, matched by name at any depth and run in the lockfile's directory.
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $install  lockfile name => command
     * @return list<array{0: string, 1: string}>
     */
    private function installs(array $files, array $install): array
    {
        $runs = [];
        foreach ($files as $file) {
            if (isset($install[basename($file)]) && is_file($this->paths->main.'/'.$file)) {
                $runs[$file] = [dirname($this->paths->main.'/'.$file), (string) $install[basename($file)]];
            }
        }

        return array_values($runs);
    }

    /** $command in $dir, or in $stack's service container. */
    private function step(string $kind, string $command, string $dir, ?Stack $stack = null): bool
    {
        if ($stack !== null) {
            $result = $stack->compose(['exec', '-T', $stack->service(), 'sh', '-c', $command], 600);
        } else {
            $process = Process::fromShellCommandline($command, $dir, null, null, 600);
            try {
                $process->run();
                $result = ['code' => $process->getExitCode() ?? 1, 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
            } catch (ProcessTimedOutException) {
                $result = ['code' => 124, 'out' => $process->getOutput(), 'err' => 'timed out after '.(int) $process->getTimeout().' s'];
            }
        }
        $where = $dir === $this->paths->main ? '' : ' in '.$this->paths->relative($dir);
        if ($result['code'] === 0) {
            ($this->say)("{$kind}: {$command}{$where} ok");

            return true;
        }
        ($this->fault)("{$kind}: {$command}{$where} failed (exit {$result['code']}): ".Worktrees::tail($result['err'] ?: $result['out']));

        return false;
    }

    /**
     * Rebuilds main's stack when the commits changed what its image or compose file is built from: the images first,
     * while the old containers keep serving, then the containers are recreated on them. False when either failed.
     *
     * @param  list<string>  $files
     */
    private function rebuildMain(array $files): bool
    {
        $stack = (array) ($this->config['stack'] ?? []);
        $compose = $stack['compose_file'] ?? 'docker-compose.yml';
        if (($rebuild = MergeCheck::rebuildFiles($files, $compose)) === []) {
            return true;
        }
        $command = "docker compose -f {$compose} build && docker compose -f {$compose} up -d --force-recreate --wait";
        $main = $this->mainStack();
        if (! $this->rebuild || $main === null || ! Stack::enabled($stack, $this->paths->main)) {
            ($this->say)('rebuild main: '.implode(', ', $rebuild)." changed; run `{$command}`");

            return true;
        }
        $project = $main->project;
        ($this->say)('rebuild main: '.implode(', ', $rebuild).' changed; building its images, main keeps serving');
        $build = $main->compose(['build'], 1800);
        if ($build['code'] !== 0) {
            ($this->fault)('rebuild main failed to build, main runs on its old images: '.Worktrees::tail($build['err'] ?: "exit {$build['code']}")."; run `{$command}`");

            return false;
        }
        ($this->say)("rebuild main: recreating its containers (`docker compose -p {$project} ps` follows them)");
        $result = $main->compose(['up', '-d', '--force-recreate', '--wait'], 600);
        if ($result['code'] !== 0) {
            ($this->fault)('rebuild main failed: '.Worktrees::tail($result['err'] ?: "exit {$result['code']}")."; run `{$command}`");

            return false;
        }
        ($this->say)("rebuilt main's stack {$project}");

        return true;
    }
}
