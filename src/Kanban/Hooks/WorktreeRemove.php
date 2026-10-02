<?php

namespace PetarSpasic\Kanban\Hooks;

use PetarSpasic\Kanban\Code\Worktrees;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Git\GitStore;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Paths;
use Throwable;

/**
 * Claude Code WorktreeRemove. Card worktrees are left alone (only `finish`/`stop` remove them); any other
 * worktree gets its stack down, its slot released, `git worktree remove --force`, and its `worktree-*` branch
 * deleted unless it has commits.
 */
final class WorktreeRemove
{
    /** @param  array<string, mixed>  $config  the whole `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private ?Store $store = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  {worktree_path, …}
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public function handle(array $payload): array
    {
        $given = (string) ($payload['worktree_path'] ?? '');
        if ($given === '') {
            return ['stdout' => '', 'stderr' => "worktree-remove: no worktree_path in the payload\n", 'exit' => 1];
        }
        $path = realpath($given) ?: rtrim($given, '/');
        try {
            if ($path === $this->paths->main || $path === $this->paths->board() || str_starts_with($path, $this->paths->board().'/')) {
                return ['stdout' => '', 'stderr' => "kanban: {$path} is not a code worktree; left alone\n", 'exit' => 0];
            }
            if (($id = $this->cardOf($path)) !== null) {
                return ['stdout' => '', 'stderr' => "kanban: {$path} is the worktree of {$id}; left for `kanban finish` or `kanban stop`\n", 'exit' => 0];
            }

            $stderr = $this->cleanUp($path, true);

            return ['stdout' => '', 'stderr' => $stderr, 'exit' => 0];
        } catch (Throwable $e) {
            return ['stdout' => '', 'stderr' => "kanban worktree-remove: {$e->getMessage()}\n", 'exit' => 1];
        }
    }

    /**
     * Removes isolated-agent worktrees (`agent-a<16 hex>`) that Claude Code never calls WorktreeRemove for: clean, no
     * git activity for $olderThan seconds, no running stack. At most $max per call and $budget seconds, so a session
     * start never waits on it. The branch is kept when it has commits.
     *
     * @return int worktrees removed
     */
    public function reclaim(int $olderThan = 86400, int $max = 10, float $budget = 120.0): int
    {
        $started = microtime(true);
        $worktrees = new Worktrees($this->paths, $this->config);
        $removed = 0;
        foreach ($this->idleAgentWorktrees($olderThan) as $dir) {
            if ($removed >= $max || microtime(true) - $started > $budget) {
                break;
            }
            $status = $worktrees->git($dir)->line(['--no-optional-locks', 'status', '--porcelain']);
            if ($status !== null && $status !== '') {
                continue;
            }
            if ($status === null) {
                continue;
            }
            try {
                if ($worktrees->registry()->find(realpath($dir) ?: $dir) !== null && $worktrees->stack($dir)->idle() !== true) {
                    continue;
                }
                $this->cleanUp(realpath($dir) ?: $dir, false);
                $removed++;
            } catch (Throwable) {
            }
        }

        return $removed;
    }

    /**
     * Isolated-agent worktrees with no activity for $olderThan seconds: what reclaim() looks at, cheap to list.
     *
     * @return list<string>
     */
    public function idleAgentWorktrees(int $olderThan = 86400): array
    {
        return array_values(array_filter(
            glob($this->paths->worktrees().'/agent-a*', GLOB_ONLYDIR) ?: [],
            fn (string $dir) => preg_match(WorktreeCreate::ISOLATED_AGENT, basename($dir)) === 1 && $this->lastActivity($dir) <= time() - $olderThan,
        ));
    }

    /** Newest change of the directory, its `.git` link and the worktree's HEAD, index and reflog. */
    private function lastActivity(string $dir): int
    {
        $times = [(int) @filemtime($dir), (int) @filemtime($dir.'/.git')];
        $admin = Paths::gitdirOf($dir.'/.git');
        foreach ($admin === null ? [] : ['HEAD', 'index', 'logs/HEAD'] as $file) {
            $times[] = (int) @filemtime($admin.'/'.$file);
        }

        return max($times);
    }

    private function cleanUp(string $path, bool $force): string
    {
        $worktrees = new Worktrees($this->paths, $this->config);
        $stderr = '';
        $branch = is_dir($path) ? $worktrees->git($path)->line(['symbolic-ref', '--short', '-q', 'HEAD']) : null;
        $entry = $worktrees->registry()->find($path);
        if ($entry !== null) {
            if ($worktrees->down($path, $entry['project'])) {
                $stderr .= "kanban: stack {$entry['project']} down, slot {$entry['slot']} released\n";
            } else {
                $stderr .= "kanban: stack {$entry['project']} down failed; slot kept for `kanban stack gc`\n";
            }
        }
        if (is_dir($path)) {
            $worktrees->remove($path, $force);
            $stderr .= "kanban: removed worktree {$path}\n";
        }
        $worktrees->prune();
        if ($branch !== null && str_starts_with($branch, 'worktree-') && $worktrees->branchExists($branch)) {
            if ($worktrees->commitsAhead($branch) === 0 && $worktrees->deleteBranch($branch, true)) {
                $stderr .= "kanban: deleted branch {$branch}\n";
            } else {
                $stderr .= "kanban: kept branch {$branch} (it has commits)\n";
            }
        }

        return $stderr;
    }

    private function cardOf(string $path): ?string
    {
        if (! $this->paths->hasBoard()) {
            return null;
        }
        $relative = $this->paths->relative($path);
        $cards = ($this->store ??= new GitStore($this->paths, $this->config))->snapshot()
            ->cards(fn (Card $card) => ($card->work()['worktree'] ?? null) === $relative);

        return $cards === [] ? null : $cards[0]->id();
    }
}
