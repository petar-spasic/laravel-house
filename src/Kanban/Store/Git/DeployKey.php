<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The key the project's local container syncs the board with: `.git/laravel-house/deploy_key`, made here, never
 * committed, and inside the `./:/app` mount the container already has. Only the container is pointed at it
 * (`GIT_SSH_COMMAND` in its compose file); this machine's git keeps using whatever key it uses today.
 */
final class DeployKey
{
    public const FILE = 'deploy_key';

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly Paths $paths, private readonly array $config = []) {}

    public function path(): string
    {
        return $this->paths->runtime(self::FILE);
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** A container syncs from this checkout (the local compose file exists) and origin is reached over ssh. */
    public function wanted(): bool
    {
        return Stack::enabled((array) ($this->config['stack'] ?? []), $this->paths->main) && $this->ssh($this->url() ?? '');
    }

    /** A container syncs from this checkout and origin is reached over https: it signs in with `KANBAN_GIT_TOKEN`. */
    public function tokenWanted(): bool
    {
        return Stack::enabled((array) ($this->config['stack'] ?? []), $this->paths->main) && str_starts_with($this->url() ?? '', 'https://');
    }

    /** What install would do, for a dry run. */
    public function plan(): ?string
    {
        return $this->wanted() && ! $this->exists() ? 'would create a deploy key for the container sync at '.$this->shown() : null;
    }

    /**
     * Creates the key when it is wanted and missing, and says how to add it to the repository. Never fatal.
     *
     * @return list<string>
     */
    public function ensure(): array
    {
        if (! $this->wanted() || $this->exists()) {
            return [];
        }
        $this->paths->ensureRuntime();
        $comment = 'laravel-house kanban '.basename($this->paths->main).'@'.gethostname();
        try {
            $made = (new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $this->path()]))->setTimeout(30);
            $made->run();
        } catch (Throwable) {
            $made = null;
        }
        if ($made === null || ! $made->isSuccessful() || ! is_file($this->path().'.pub')) {
            return ['no deploy key made for the container sync: ssh-keygen is missing or failed; put a passphrase-less key at '.$this->shown().' yourself'];
        }
        chmod($this->path(), 0600);
        $public = trim((string) file_get_contents($this->path().'.pub'));
        $lines = [
            'deploy key for the container sync: '.$this->shown().' (stays on this machine; the container reads it from the project mount)',
            $public,
            'add it to the repository as a deploy key with write access (a repository admin can):',
        ];
        if (($repo = $this->githubRepo()) !== null) {
            $lines[] = "  https://github.com/{$repo}/settings/keys/new  (title: {$comment}; tick Allow write access)";
            $lines[] = '  or: gh repo deploy-key add '.$this->shown().'.pub --allow-write';
        } else {
            $lines[] = '  your git host: the repository\'s deploy keys, with write access';
        }

        return $lines;
    }

    private function shown(): string
    {
        return $this->paths->relative($this->path());
    }

    private function url(): ?string
    {
        $git = new Git($this->paths->main);
        $url = $git->line(['remote', 'get-url', (string) ($this->config['remote'] ?? 'origin')]);

        return $url === null || $url === '' ? null : $url;
    }

    private function ssh(string $url): bool
    {
        return preg_match('#^ssh://#', $url) === 1 || preg_match('#^[A-Za-z0-9._-]+@[^/:\s]+:#', $url) === 1;
    }

    /** "owner/repo" when origin is a github.com address. */
    private function githubRepo(): ?string
    {
        return preg_match('#github\.com[:/]+([^/\s]+/[^/\s]+?)(?:\.git)?/?$#', (string) $this->url(), $m) === 1 ? $m[1] : null;
    }
}
