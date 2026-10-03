<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What `kanban:install` needs from the project and this machine. A fail stops the install; a warn is for the owner to
 * confirm. Whether the repository is private and whether the board is shared stay the owner's answers.
 */
final class Prerequisites
{
    public const GIT = '2.42';

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly Paths $paths, private readonly array $config = []) {}

    /** @return list<array{0: 'ok'|'warn'|'fail', 1: string}> */
    public function check(): array
    {
        $main = $this->paths->main;
        $git = new Git($main);
        $results = [];

        preg_match('/(\d+\.\d+(?:\.\d+)?)/', (string) $git->line(['--version']), $m);
        $version = $m[1] ?? '0';
        $results[] = version_compare($version, self::GIT, '>=')
            ? ['ok', "git {$version}"]
            : ['fail', "git {$version}: the board needs git ≥ ".self::GIT];

        $composer = json_decode((string) @file_get_contents($main.'/composer.json'), true);
        if (isset($composer['require']['petar-spasic/laravel-house'])) {
            $results[] = ['warn', 'petar-spasic/laravel-house is in require: move it to require-dev'];
        }
        $results[] = is_file($main.'/boost.json')
            ? ['ok', 'boost.json']
            : ['warn', 'no boost.json: without Laravel Boost, the kanban skill reaches agents only through the CLAUDE.md block'];

        $dirty = trim($git->attempt(['status', '--porcelain', '--untracked-files=no'])->out);
        $results[] = $dirty === ''
            ? ['ok', 'main is clean']
            : ['warn', 'main has uncommitted changes: commit them first, so the install\'s own changes stand alone'];

        $remote = (string) ($this->config['remote'] ?? 'origin');
        $url = (string) $git->line(['config', '--get', "remote.{$remote}.url"]);
        $results[] = $url === '' ? ['warn', "no {$remote}: the board stays on this machine"] : $this->remote($remote, $url);

        $stack = (array) ($this->config['stack'] ?? []);
        if (! Stack::enabled($stack, $main)) {
            $results[] = ['warn', 'no '.($stack['compose_file'] ?? 'compose file').': cards get a clone of main and no stack'];

            return $results;
        }
        $compose = Stack::docker(['compose', 'version', '--short'], 15);
        $results[] = $compose !== null && version_compare(trim($compose), '2', '>=')
            ? ['ok', 'docker compose '.trim($compose)]
            : ['warn', 'docker compose v2 not found: card stacks need it'];
        if ($url !== '') {
            array_push($results, ...$this->containerSync($remote, $url));
        }
        $env = DotEnv::parse($main.'/.env');
        if (($env['WEB_BIND'] ?? '0.0.0.0') !== '127.0.0.1' && (string) ($this->config['ui']['token'] ?? '') === '') {
            $results[] = ['warn', '/kanban has no login and the local stack answers on the LAN: set KANBAN_UI_TOKEN or WEB_BIND=127.0.0.1 in .env, or confirm it stays open'];
        }

        return $results;
    }

    /** @return array{0: 'ok'|'warn'|'fail', 1: string} */
    private function remote(string $remote, string $url): array
    {
        $process = new Process(['git', 'ls-remote', '--heads', $remote], $this->paths->main, ['GIT_TERMINAL_PROMPT' => '0'], null, 20);
        $process->run();
        if (! $process->isSuccessful()) {
            return ['warn', "{$remote} is not reachable, so the board is not published yet: ".trim(strtok($process->getErrorOutput(), "\n") ?: 'git ls-remote failed')];
        }

        return trim($process->getOutput()) === ''
            ? ['fail', "{$remote} is empty: push main first (with the owner's OK), or the board branch becomes the default branch and a clone checks out no code"]
            : ['ok', "{$remote} reachable"];
    }

    /**
     * What the container needs to sync the board page: a URL it can resolve on its own, and its key or token.
     *
     * @return list<array{0: 'ok'|'warn'|'fail', 1: string}>
     */
    private function containerSync(string $remote, string $url): array
    {
        $results = [];
        $effective = trim((string) (new Git($this->paths->main))->line(['ls-remote', '--get-url', $remote]));
        if ($effective !== '' && $effective !== $url) {
            $results[] = ['fail', "{$remote} is rewritten by a url.*.insteadOf rule, which the container does not have: put {$effective} in .git/config"];
        }
        if (str_starts_with($url, 'https://')) {
            $token = (string) (DotEnv::parse($this->paths->main.'/.env')['KANBAN_GIT_TOKEN'] ?? getenv('KANBAN_GIT_TOKEN') ?: '');
            $results[] = $token !== ''
                ? ['ok', 'https origin with KANBAN_GIT_TOKEN']
                : ['warn', "https origin: set KANBAN_GIT_TOKEN in .env (a fine-grained token with read and write on this repository's contents), or the board page does not sync"];

            return $results;
        }
        if (preg_match('#^(?:ssh://)?(?:[^@/]+@)?([^:/]+)[:/]#', $url, $m) !== 1) {
            return $results;
        }
        if ((new ExecutableFinder)->find('ssh-keygen') === null) {
            $results[] = ['warn', 'ssh-keygen not found: the container syncs with a deploy key the install makes'];
        }
        $ssh = new Process(['ssh', '-G', $m[1]], null, null, null, 10);
        $ssh->run();
        if ($ssh->isSuccessful() && preg_match('/^hostname (\S+)$/m', $ssh->getOutput(), $h) === 1 && strtolower($h[1]) !== strtolower($m[1])) {
            $results[] = gethostbyname($m[1]) === $m[1]
                ? ['fail', "{$m[1]} is an alias in ~/.ssh/config for {$h[1]}; the container has no ssh config: put {$h[1]} in {$remote}'s URL"]
                : ['warn', "~/.ssh/config sends {$m[1]} to {$h[1]}; the container has no ssh config and connects to {$m[1]} itself"];
        }

        return $results;
    }
}
