<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * The merge clone (`.claude/worktrees/_merge`, branch `merge`) and its stack, where the merge queue checks a merged tree
 * and its merger works. Its inputs are pinned in main (`refs/merge-queue/<card>/{base,card,merge,result}`) and fetched
 * into it as `refs/merge/*`, the base also as its `<main>`, so its gates compare against the base. Git that touches its
 * working tree runs in its container (CloneGit).
 */
final class MergeClone
{
    /** What the merge clone had installed, per lockfile (`finish.install`): `{<path>: <its blob id>}` in the runtime directory. */
    public const INSTALLED = 'merge-installed.json';

    public readonly string $path;

    private readonly Worktrees $worktrees;

    private readonly CloneGit $git;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly Paths $paths, private readonly array $config)
    {
        $this->path = $paths->mergeClone();
        $this->worktrees = new Worktrees($paths, $config);
        $this->git = new CloneGit($paths, $config);
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }

    /**
     * The clone, scrubbed, with its stack up: made when missing (what was installed in one before is forgotten), its
     * stack brought up when it is not running, and with $rebuild recreated when it is not on the current docker files.
     * StackFailed when `_merge` is not this checkout's merge clone or the stack fails.
     *
     * @return array<string, mixed> the registry entry
     */
    public function ensure(bool $rebuild = true): array
    {
        if (! file_exists($this->path) && ! is_link($this->path)) {
            $this->worktrees->add($this->path, 'merge');
            $this->worktrees->copyDependencies($this->path);
            @unlink($this->paths->runtime(self::INSTALLED));
        }
        if ($this->foreign()) {
            throw new StackFailed($this->paths->relative($this->path)." is not this checkout's merge clone: remove it");
        }
        $this->git->scrub($this->path);
        $entry = $this->worktrees->registry()->find($this->path);
        if ($entry === null || ! $this->running() || ($rebuild && ($this->worktrees->stackRecord($this->path)['hash'] ?? null) !== $this->worktrees->dockerHash($this->path))) {
            $entry = $this->worktrees->up($this->path, 'merge', null);
        }
        $this->worktrees->await($entry);

        return $entry;
    }

    /** Whether the merge stack runs. */
    public function running(): bool
    {
        $entry = $this->worktrees->registry()->find($this->path);

        return $entry !== null && $this->worktrees->stack($this->path, $entry['project'])->running();
    }

    /** Whether `_merge` is something other than this checkout's merge clone, which nothing here may run git in. */
    public function foreign(): bool
    {
        $git = $this->path.'/.git';

        return is_link($this->path) || is_link($git) || is_link($git.'/config') || is_link($git.'/info') || ! $this->worktrees->isClone($this->path);
    }

    /** Pins $sha in main as `refs/merge-queue/<card>/<name>`. */
    public function pin(string $card, string $name, string $sha): void
    {
        $this->main()->run(['update-ref', self::pins($card).$name, $sha]);
    }

    public function pinned(string $card, string $name): ?string
    {
        return $this->main()->line(['rev-parse', '--verify', '-q', self::pins($card).$name]);
    }

    /** Deletes the card's pins. */
    public function unpin(string $card): void
    {
        foreach (array_filter(explode("\n", $this->main()->attempt(['for-each-ref', '--format=%(refname)', self::pins($card)])->out)) as $ref) {
            $this->main()->attempt(['update-ref', '-d', $ref]);
        }
    }

    /** The card's pins into the clone as `refs/merge/*`, and the base as its `<main>`; by path, never the clone's remote. */
    public function fetchPins(string $card): void
    {
        $main = $this->worktrees->mainBranch();
        // --update-head-ok: a clone someone left on its main; every caller checks out after it
        Git::untrusted($this->path)->run(['fetch', '-q', '--no-tags', '--update-head-ok', $this->paths->main,
            '+'.self::pins($card).'*:refs/merge/*', '+'.self::pins($card)."base:refs/heads/{$main}"]);
    }

    /** The clone on branch `merge` at $ref, with nothing else in its working tree and no merge left in it. */
    public function checkout(string $ref): void
    {
        $this->container(['reset', '-q', '--hard']);
        $this->container(['checkout', '-q', '-f', '-B', 'merge', $ref]);
        $this->container(['clean', '-fdq']);
        // Laravel builds them again; one from another card's tree would name classes that are gone. git never follows
        // a symlink the tree makes of them
        $this->container(['clean', '-fqX', '--', 'bootstrap/cache']);
    }

    /**
     * The merge of `refs/merge/card` into `refs/merge/base` as git leaves it in the clone, for the merger: the files left
     * unmerged; none when rerere resolved every hunk, the merge then committed.
     *
     * @return list<string>
     */
    public function conflict(string $message): array
    {
        $this->checkout('refs/merge/base');
        // a merge that stops on conflicts exits 1: the index says which
        $this->container([...$this->identity(), 'merge', '-q', '--no-ff', '-m', $message, 'refs/merge/card'], [0, 1]);
        $files = $this->unmerged();
        if ($files === [] && is_file($this->path.'/.git/MERGE_HEAD')) {
            $this->container([...$this->identity(), 'commit', '-q', '--no-edit']);
        }

        return $files;
    }

    /**
     * The merge of the card again with nothing git remembers of its conflicts (rerere): its files left unmerged for the
     * merger, as conflict() leaves them.
     *
     * @return list<string>
     */
    public function forget(string $message): array
    {
        $this->checkout('refs/merge/base');
        // the remembered resolution goes into the files only, so the index still names each conflict to forget
        $this->container(['-c', 'rerere.autoUpdate=false', 'merge', '-q', '--no-ff', '--no-commit', 'refs/merge/card'], [0, 1]);
        $this->container(['rerere', 'forget', '.']);

        return $this->conflict($message);
    }

    /** The clone's branch `merge` fetched into main as the card's pin $name; its sha. */
    public function take(string $card, string $name): string
    {
        // the upload-pack serving it runs in the clone, with the clone's config: untrusted
        Git::untrusted($this->paths->main)->run(['fetch', '-q', '--no-tags', $this->path, '+refs/heads/merge:'.self::pins($card).$name]);

        return (string) $this->pinned($card, $name);
    }

    /**
     * The commits $from..$to (the merger's fixes of an earlier round) on top of the clone's HEAD, one main already holds
     * kept empty; false when they conflict.
     */
    public function replay(string $from, string $to): bool
    {
        // a cherry-pick of several commits that stopped (a crash) outlives a reset and would refuse this one
        $this->container(['cherry-pick', '--quit']);
        $pick = $this->git->inContainer($this->path, [...$this->identity(), 'cherry-pick', '--keep-redundant-commits', "{$from}..{$to}"], 600);
        if (! $pick->ok()) {
            $this->git->inContainer($this->path, ['cherry-pick', '--abort']);
        }

        return $pick->ok();
    }

    public function head(): string
    {
        return Git::untrusted($this->path)->line(['rev-parse', '--verify', '-q', 'HEAD']) ?? throw new GitFailed('the merge clone has no HEAD');
    }

    /** Its stack down and its slot released; the clone stays for the next merge. */
    public function down(): bool
    {
        return ! $this->exists() || $this->worktrees->down($this->path);
    }

    /** @return list<string> the index's unmerged paths: no content is read */
    public function unmerged(): array
    {
        $files = [];
        foreach (array_filter(explode("\0", Git::untrusted($this->path)->attempt(['ls-files', '-u', '-z'])->out)) as $line) {
            $files[substr($line, strpos($line, "\t") + 1)] = true;
        }

        return array_keys($files);
    }

    public static function pins(string $card): string
    {
        return "refs/merge-queue/{$card}/";
    }

    /**
     * @param  list<string>  $args
     * @param  list<int>  $ok  the exits that are no failure
     */
    private function container(array $args, array $ok = [0]): void
    {
        $result = $this->git->inContainer($this->path, $args, 600);
        if (! in_array($result->code, $ok, true)) {
            throw new GitFailed('git '.implode(' ', array_slice($args, 0, 3))." in the merge clone failed (exit {$result->code}): ".Worktrees::tail($result->err ?: $result->out));
        }
    }

    /** @return list<string> main's identity, for the commits made in the clone */
    private function identity(): array
    {
        $identity = [];
        foreach (['user.name', 'user.email'] as $key) {
            if (($value = $this->main()->line(['config', '--get', $key])) !== null) {
                array_push($identity, '-c', "{$key}={$value}");
            }
        }

        return $identity;
    }

    private function main(): Git
    {
        return new Git($this->paths->main);
    }
}
