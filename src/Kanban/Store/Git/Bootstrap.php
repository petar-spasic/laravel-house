<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** Creates the board (orphan branch + worktree) and attaches it on every machine. */
final class Bootstrap
{
    public const HOOKS_PATH = 'vendor/petar-spasic/laravel-house/githooks';

    /** The git config githooks/commit-msg reads; unset rejects. */
    public const REJECT_CO_AUTHORED = 'kanban.rejectCoAuthored';

    public const IGNORE = '/docs/kanban/';

    /** Set when ls-remote failed, so attach can tell "no board on origin" from "origin not reachable". */
    private bool $unreachable = false;

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config = [],
    ) {}

    /**
     * Creates the `kanban` branch with the stub board unless it exists here or on origin, then attaches.
     *
     * @return list<string>
     */
    public function install(string $key, bool $dryRun = false, bool $force = false): array
    {
        if (preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $key) !== 1) {
            throw new Invalid("invalid key '{$key}' (2-10 uppercase letters/digits, starting with a letter)");
        }
        if (! is_dir($this->paths->gitDir())) {
            throw new PolicyRefused('kanban:install runs in the main checkout (its .git is a directory)');
        }
        $lines = [];
        if ($this->attached() || $this->hasLocalBranch() || $this->hasRemoteBranch()) {
            $lines = $dryRun ? ['would attach the existing kanban branch'] : $this->attach($force);
        } elseif ($dryRun) {
            $lines[] = 'would create orphan branch kanban at '.Paths::BOARD;
            $lines[] = 'would write the stub board (key '.$key.') and commit "Kanban: initialize"';
            $lines[] = 'would configure merge.kanban.driver, core.hooksPath and '.self::REJECT_CO_AUTHORED;
        } else {
            $this->main()->run(['worktree', 'add', '-q', '--orphan', '-b', BoardRepo::BRANCH, Paths::BOARD]);
            $this->writeStubs($key);
            $board = new Git($this->paths->board());
            $board->run(['add', '-A']);
            $board->run(['commit', '-q', '--no-verify', '-m', 'Kanban: initialize']);
            $lines[] = 'created branch kanban at '.Paths::BOARD.' (key '.$key.')';
            $lines = array_merge($lines, $this->configure($force));
        }
        if ($dryRun) {
            $lines[] = $this->ignored() ? '.gitignore ok' : 'would add '.self::IGNORE.' to .gitignore';
            if (($plan = (new DeployKey($this->paths, $this->config))->plan()) !== null) {
                $lines[] = $plan;
            }
        } elseif ($this->ignore()) {
            $lines[] = '.gitignore += '.self::IGNORE.' (commit it on main)';
        }

        return $lines;
    }

    /**
     * Checks out the board worktree from the local or origin branch and configures this machine.
     *
     * @return list<string>
     */
    public function attach(bool $force = false): array
    {
        $board = $this->paths->board();
        $lines = [];
        if ($this->attached()) {
            $lines[] = 'board attached at '.Paths::BOARD;
        } elseif (is_dir($board) && (scandir($board) ?: []) !== ['.', '..']) {
            throw new PolicyRefused(Paths::BOARD.' exists but is not the kanban worktree; move it away and run attach again');
        } elseif ($this->hasLocalBranch()) {
            $this->main()->run(['worktree', 'prune']);
            $this->main()->run(['worktree', 'add', '-q', Paths::BOARD, BoardRepo::BRANCH]);
            $lines[] = 'attached local branch kanban at '.Paths::BOARD;
        } elseif ($this->hasRemoteBranch()) {
            $remote = $this->remote();
            // the clone already holds origin/kanban: an unreachable remote does not stop it from joining
            $fetched = $this->main()->attempt(['fetch', '-q', $remote, '+refs/heads/kanban:refs/remotes/'.$remote.'/kanban']);
            if (! $fetched->ok() && ! $this->hasTrackingRef()) {
                throw new RemoteFailed('fetch failed: '.trim($fetched->err));
            }
            $this->main()->run(['worktree', 'prune']);
            $this->main()->run(['worktree', 'add', '-q', '--track', '-b', BoardRepo::BRANCH, Paths::BOARD, $remote.'/kanban']);
            $lines[] = "attached {$remote}/kanban at ".Paths::BOARD;
        } elseif ($this->unreachable) {
            throw new RemoteFailed('cannot reach '.$this->remote().' to look for the kanban branch: fix access to it, then run attach again');
        } else {
            throw new NotFound('no kanban branch here or on '.$this->remote().': run `php artisan kanban:install`');
        }

        return array_merge($lines, $this->configure($force));
    }

    /**
     * Per-machine git config: the merge driver (always), hooksPath (only when unset, or forced) and whether
     * commit-msg rejects Co-Authored-By trailers (`githooks.reject_co_authored`); and the container's deploy key when
     * it is wanted and missing.
     *
     * @return list<string>
     */
    public function configure(bool $force = false): array
    {
        $main = $this->main();
        $driver = 'php '.escapeshellarg($this->paths->main.'/vendor/bin/kanban').' merge-driver %O %A %B %P';
        $main->run(['config', 'merge.kanban.name', 'laravel-house kanban JSON merge']);
        $main->run(['config', 'merge.kanban.driver', $driver]);
        $lines = ['merge driver: '.$driver];
        $hooks = $main->line(['config', '--get', 'core.hooksPath']);
        if ($hooks === null || $hooks === '' || $force) {
            $main->run(['config', 'core.hooksPath', self::HOOKS_PATH]);
            $lines[] = 'core.hooksPath: '.self::HOOKS_PATH;
        } elseif ($hooks !== self::HOOKS_PATH) {
            $lines[] = "core.hooksPath kept: {$hooks}";
        }
        $reject = self::rejectsCoAuthored($this->config) ? 'true' : 'false';
        $main->run(['config', self::REJECT_CO_AUTHORED, $reject]);
        $lines[] = self::REJECT_CO_AUTHORED.": {$reject}";
        $this->paths->ensureRuntime();

        return [...$lines, ...(new DeployKey($this->paths, $this->config))->ensure()];
    }

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public static function rejectsCoAuthored(array $config): bool
    {
        return (bool) ($config['githooks']['reject_co_authored'] ?? true);
    }

    public function attached(): bool
    {
        $out = (string) $this->main()->line(['worktree', 'list', '--porcelain']);
        $board = realpath($this->paths->board()) ?: $this->paths->board();
        foreach (preg_split('/\n\n+/', trim($out)) ?: [] as $block) {
            if (preg_match('/^worktree (.+)$/m', $block, $path) && (realpath($path[1]) ?: $path[1]) === $board) {
                return str_contains($block, "\nbranch refs/heads/".BoardRepo::BRANCH) && is_file($this->paths->board('.git'));
            }
        }

        return false;
    }

    public function ignored(): bool
    {
        $file = $this->paths->main.'/.gitignore';
        $lines = is_file($file) ? array_map('trim', file($file) ?: []) : [];

        return array_intersect($lines, ['/docs/kanban/', '/docs/kanban', 'docs/kanban/', 'docs/kanban']) !== [];
    }

    /** Adds the board to main's .gitignore; true when the file changed. */
    public function ignore(): bool
    {
        if ($this->ignored()) {
            return false;
        }
        $file = $this->paths->main.'/.gitignore';
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        $prefix = $current === '' || str_ends_with($current, "\n") ? '' : "\n";
        file_put_contents($file, $current.$prefix.self::IGNORE."\n");

        return true;
    }

    private function writeStubs(string $key): void
    {
        $stubs = dirname(__DIR__, 4).'/stubs/board';
        $now = Clock::now();
        $files = [
            'kanban.json' => 'kanban.json',
            '.gitattributes' => '.gitattributes',
            'README.md' => 'README.md',
            'project/epic.json' => 'epic.json',
            'project/work/board.json' => 'board.work.json',
        ];
        foreach ($files as $target => $stub) {
            $content = strtr((string) file_get_contents("{$stubs}/{$stub}"), ['{{key}}' => $key, '{{now}}' => $now]);
            if (str_ends_with($target, '.json')) {
                $content = Json::encode(Json::decode($content), Json::kindOf($target));
            }
            Json::write($this->paths->board($target), $content);
        }
    }

    private function hasLocalBranch(): bool
    {
        return $this->main()->attempt(['rev-parse', '--verify', '-q', 'refs/heads/'.BoardRepo::BRANCH])->ok();
    }

    private function hasRemoteBranch(): bool
    {
        if (! $this->main()->attempt(['remote', 'get-url', $this->remote()])->ok()) {
            return false;
        }
        if ($this->hasTrackingRef()) {
            return true;
        }
        $result = $this->main()->attempt(['ls-remote', '--heads', $this->remote(), BoardRepo::BRANCH]);
        $this->unreachable = ! $result->ok();

        return $result->ok() && trim($result->out) !== '';
    }

    private function hasTrackingRef(): bool
    {
        return $this->main()->attempt(['rev-parse', '--verify', '-q', 'refs/remotes/'.$this->remote().'/'.BoardRepo::BRANCH])->ok();
    }

    private function remote(): string
    {
        return (string) ($this->config['remote'] ?? 'origin');
    }

    private function main(): Git
    {
        return new Git($this->paths->main);
    }
}
