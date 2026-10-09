<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\GitResult;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;
use Throwable;

/** Git that touches a clone's working tree: in the clone's container, where its agents run git, or on this machine after scrub(). */
final class CloneGit
{
    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config = [],
    ) {}

    /**
     * Rewrites the clone's git config from a template (its format, `origin` naming main by path, main's identity and hooks
     * path, `kanban.main`; for the merge clone also rerere) and removes `info/attributes` and the hooks.
     */
    public function scrub(string $clone): void
    {
        $git = $clone.'/.git';
        if (! is_dir($git)) {
            return;
        }
        $main = new Git($this->paths->main);
        $own = fn (string $key) => $main->line(['config', '--file', $git.'/config', '--get', $key]);
        $settings = [
            'core.repositoryformatversion' => $own('core.repositoryformatversion') ?? '0',
            'extensions.objectformat' => $own('extensions.objectformat'),
            'extensions.refstorage' => $own('extensions.refstorage'),
            'core.filemode' => 'true',
            'core.bare' => 'false',
            'core.logallrefupdates' => 'true',
            'remote.origin.url' => $this->paths->main,
            'remote.origin.fetch' => '+refs/heads/*:refs/remotes/origin/*',
            'user.name' => $main->line(['config', '--get', 'user.name']),
            'user.email' => $main->line(['config', '--get', 'user.email']),
            'core.hooksPath' => $main->line(['config', '--get', 'core.hooksPath']),
            Paths::CLONE_KEY => $this->paths->main,
        ];
        if ((new Worktrees($this->paths, $this->config))->isMergeClone($clone)) {
            $settings += ['rerere.enabled' => 'true', 'rerere.autoUpdate' => 'true'];
        }
        $tmp = $git.'/config.kanban';
        file_put_contents($tmp, '');
        foreach (array_filter($settings, fn (?string $value) => $value !== null && $value !== '') as $key => $value) {
            $main->run(['config', '--file', $tmp, $key, $value]);
        }
        rename($tmp, $git.'/config');
        @unlink($git.'/info/attributes');
        (new Process(['rm', '-rf', $git.'/hooks']))->run();
    }

    /**
     * `git $args` in the clone: in its container when its stack record names one, else onHost().
     *
     * @param  list<string>  $args
     */
    public function inContainer(string $clone, array $args, float $timeout = 120): GitResult
    {
        $record = (new Worktrees($this->paths, $this->config))->stackRecord($clone);
        $exec = $this->paths->main.'/vendor/bin/kanban-exec';
        if (($record['shell'] ?? null) !== 'container' || ! is_string($record['container'] ?? null) || ! is_file($exec)) {
            return $this->onHost($clone, $args, $timeout);
        }
        $command = implode(' ', array_map('escapeshellarg', ['git', ...self::topLevel($args)]));
        $process = new Process([$exec, $record['container'], realpath($clone) ?: $clone, $command], null, null, null, $timeout);
        try {
            $process->run();
        } catch (Throwable $e) {
            return new GitResult(127, '', $e->getMessage());
        }

        return new GitResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * `git $args` in the clone on this machine, after scrub(), as Git::untrusted.
     *
     * @param  list<string>  $args
     */
    public function onHost(string $clone, array $args, float $timeout = 120): GitResult
    {
        $this->scrub($clone);

        return Git::untrusted($clone)->attempt(self::topLevel($args), null, $timeout);
    }

    /**
     * A status leaves out the repositories nested in the working tree: their own config would run, and a `.gitmodules`
     * outranks any `-c`.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private static function topLevel(array $args): array
    {
        return ($args[0] ?? null) === 'status' ? ['status', '--ignore-submodules=all', ...array_slice($args, 1)] : $args;
    }
}
