<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeAgents;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeSettings;
use PetarSpasic\LaravelHouse\Kanban\Console\Standalone;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use PetarSpasic\LaravelHouse\Kanban\Support\Processes;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Headless card agents for `kanban run`: each is `claude -p --agent kanban-worker|kanban-planner|kanban-evaluator|kanban-merger` in main's checkout,
 * with the model and effort of `kanban.agents.<role>` (read at each launch) passed as flags and none inherited from the environment,
 * detached (its own session, so stopping `run` leaves it working), bound to its card by its session id. A run's pid
 * file sits in `runs/` until the run is reaped; its result JSON and stderr beside it; one line per ended run in
 * `runs.jsonl`.
 */
final class AgentRun
{
    public const WORKER = 'kanban-worker';

    public const EVALUATOR = 'kanban-evaluator';

    public const PLANNER = 'kanban-planner';

    public const MERGER = 'kanban-merger';

    /** A `stop` under way younger than this holds the card; an older mark is one a killed `stop` left. */
    public const STOPPING_SECONDS = 600;

    /** The launching session's model and effort, which would otherwise override the flags or the agent file. */
    private const INHERITED = ['CLAUDE_EFFORT' => false, 'CLAUDE_CODE_EFFORT_LEVEL' => false, 'ANTHROPIC_MODEL' => false, 'CLAUDE_CODE_SUBAGENT_MODEL' => false];

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly Paths $paths, private readonly array $config) {}

    /** Why `kanban run` cannot drive this checkout, or null. */
    public function unusable(): ?string
    {
        $finder = new ExecutableFinder;

        $missing = array_values(array_filter([self::WORKER, self::PLANNER, self::EVALUATOR, self::MERGER], fn (string $agent) => ! is_file($this->paths->main."/.claude/agents/{$agent}.md")));

        return match (true) {
            ($this->config['agents']['shell'] ?? 'container') !== 'container' || ($this->config['stack']['compose_file'] ?? null) === null => 'kanban run needs card containers (agents.shell container and stack.compose_file set): a headless agent may run only the commands Guard routes into one',
            $finder->find('setsid') === null => 'kanban run needs setsid (Linux) to detach the agents',
            $finder->find('claude') === null => 'kanban run needs Claude Code: no `claude` on PATH',
            $missing !== [] => 'kanban run needs .claude/agents/'.implode('.md, .claude/agents/', $missing).'.md: run `vendor/bin/kanban doctor --fix`',
            default => null,
        };
    }

    /**
     * Launches a new session for the card, or resumes $resume; returns the session id. $worktree, absolute, is where the
     * agent works instead of the card's own clone (a merger's merge clone).
     */
    public function launch(Card $card, string $type, ?string $resume = null, ?string $worktree = null): string
    {
        if ($this->stopping($card->id())) {
            throw new PolicyRefused("{$card->id()} is being stopped: no agent is launched for it");
        }
        $session = $resume ?? self::uuid();
        if ($worktree === null) {
            $worktree = (string) ($card->work()['worktree'] ?? '');
            $worktree = $this->paths->main.'/'.$worktree;
        }
        $worktree = realpath($worktree) ?: $worktree;
        $prompt = $resume === null
            ? "Card {$card->id()}. Worktree {$worktree}"
            : "Resumed for card {$card->id()}: run vendor/bin/kanban context and act on what it shows (section 5 of your instructions).";
        (new Runtime($this->paths))->saveAgent(['agent_id' => $session, 'agent_type' => $type, 'card' => $card->id(), 'worktree' => $worktree,
            'bound_at' => Clock::now(), 'started_at' => Clock::now(), 'stopped_at' => null, 'stop_blocks' => 0, 'headless' => true]);

        $tools = array_values((array) ($this->config['agents']['allowed_tools'] ?? []));
        // Guard's routed commands, allowed here and not through settings.local.json, which an untrusted checkout ignores
        $main = realpath($this->paths->main) ?: $this->paths->main;
        $allow = ['permissions' => ['allow' => ['Bash('.Guard::kanban($main).' *)', ClaudeSettings::execPermission($main)]]];
        $settings = $this->settings($type);
        $pinned = [];
        foreach (array_filter($settings) as $key => $value) {
            array_push($pinned, "--{$key}", $value);
        }
        $args = ['claude', '-p', '--agent', $type, ...$pinned, '--permission-mode', 'acceptEdits', '--permission-prompts', 'none',
            '--settings', json_encode($allow, JSON_UNESCAPED_SLASHES),
            ...($tools === [] ? [] : ['--allowedTools', implode(',', $tools)]),
            '--output-format', 'json', $resume === null ? '--session-id' : '--resume', $session, $prompt];
        $dir = $this->paths->ensureRuntime('runs');
        $process = Process::fromShellCommandline('setsid nohup '.implode(' ', array_map('escapeshellarg', $args))
            .' > '.escapeshellarg("{$dir}/{$session}.json").' 2> '.escapeshellarg("{$dir}/{$session}.log").' < /dev/null & echo $!',
            $this->paths->main, ['KANBAN_SESSION' => false, 'KANBAN_TRANSCRIPT' => false, ...self::INHERITED]);
        $process->mustRun();
        $run = [
            'pid' => (int) trim($process->getOutput()), 'card' => $card->id(), 'type' => $type, 'started' => Clock::now(),
            'stage' => $card->stage(), 'approved' => $card->work()['approved']['head'] ?? null, 'planned' => $card->planned()['at'] ?? null,
        ] + $settings;
        file_put_contents("{$dir}/{$session}.pid", json_encode($run));
        // a `stop` that marked the card meanwhile looked for its runs before this one had a pid file
        if ($this->stopping($card->id())) {
            // until the forked shell has exec'd, it is in this process's group and its command line is not the run's yet
            for ($until = microtime(true) + 2; self::owns($run['pid'], $session) === false && posix_kill($run['pid'], 0) && microtime(true) < $until;) {
                usleep(20_000);
            }
            // still not exec'd: our own fresh child, whose pid no other process can hold yet, and that end() would not
            // signal (it signals a pid only once it is proven the run's)
            if (self::owns($run['pid'], $session) === false && posix_kill($run['pid'], 0)) {
                posix_kill($run['pid'], SIGKILL);
            }
            $this->end([['session' => $session] + $run]);
            throw new PolicyRefused("{$card->id()} is being stopped: its agent was ended as it launched");
        }
        if ($type === self::MERGER) {
            // launches since the merger's last result: the third that ends with none blocks the card
            (new MergeState($this->paths))->update(fn (array $state) => ($state['card'] ?? null) === $card->id()
                ? ['merger_runs' => (int) ($state['merger_runs'] ?? 0) + 1] + $state : $state);
        }

        return $session;
    }

    /**
     * Marks the card `stopping/<id>` from before its agent is ended until its stage has changed: meanwhile no `kanban run`
     * pass launches or resumes an agent for it, or starts it again. A `stop` that fails after it ended the agent leaves
     * the mark, which holds for STOPPING_SECONDS.
     */
    public function markStopping(string $id): void
    {
        file_put_contents($this->paths->ensureRuntime('stopping')."/{$id}", (string) getmypid());
    }

    public function unmarkStopping(string $id): void
    {
        @unlink($this->paths->runtime("stopping/{$id}"));
    }

    public function stopping(string $id): bool
    {
        $at = @filemtime($this->paths->runtime("stopping/{$id}"));

        return $at !== false && time() - $at < self::STOPPING_SECONDS;
    }

    /** `(model, effort)` the run of $session was launched with; `agent file` for what its agent file decides. */
    public function pins(string $session): string
    {
        $run = json_decode((string) @file_get_contents($this->paths->runtime("runs/{$session}.pid")), true);

        return '('.($run['model'] ?? 'agent file').', '.($run['effort'] ?? 'agent file').')';
    }

    /**
     * `kanban.agents.<role>` model and effort as main's config/kanban.php says now: a change reaches the next launch of
     * a run already going. The values the run started with while the file does not load.
     *
     * @return array{model: ?string, effort: ?string}
     */
    private function settings(string $type): array
    {
        try {
            $config = ['agents' => Standalone::config($this->paths->main)['agents'] ?? []];
        } catch (Throwable) {
            $config = $this->config;
        }

        return ['model' => ClaudeAgents::setting($config, $type, 'model'), 'effort' => ClaudeAgents::setting($config, $type, 'effort')];
    }

    /** @return array<string, array<string, mixed>> runs still going, by session */
    public function running(): array
    {
        $runs = [];
        foreach ($this->pidFiles() as $session => $run) {
            if (self::alive((int) ($run['pid'] ?? 0), $session)) {
                $runs[$session] = $run;
            }
        }

        return $runs;
    }

    /**
     * Ends the card's headless runs (with $type, only those of that agent type), and every command they started: Claude
     * Code runs each shell in a session of its own, so a run's process group alone would leave them working in the clone.
     * Only a run whose process is proven to be it (owns()) is ended; each one ended is reaped as ended() would.
     *
     * @return list<array<string, mixed>> the runs that were live, each with its `session`
     */
    public function stopCard(string $id, int $grace = 10, ?string $type = null): array
    {
        $runs = [];
        foreach ($this->running() as $session => $run) {
            if (($run['card'] ?? null) === $id && ($type === null || ($run['type'] ?? null) === $type) && self::owns((int) $run['pid'], $session) === true) {
                $runs[] = ['session' => $session] + $run;
            }
        }

        return $this->end($runs, $grace);
    }

    /**
     * SIGTERM to the process group of each run and of each of its descendants, taken from /proc before the first signal
     * (a parent that dies leaves its children to init); SIGKILL after $grace seconds to the groups left and to what their
     * members started meanwhile. Then each run is reaped.
     *
     * @param  list<array<string, mixed>>  $runs  each with its `session` and `pid`
     * @return list<array<string, mixed>>
     */
    private function end(array $runs, int $grace = 10): array
    {
        if ($runs === []) {
            return [];
        }
        $pids = array_map(fn (array $r) => (int) $r['pid'], $runs);
        $groups = self::groups($pids);
        // each run's own pid too: one just launched may not have left this process's group yet
        $signal = function (int $signal) use (&$groups, $runs) {
            foreach ($groups as $group) {
                posix_kill(-$group, $signal);
            }
            foreach ($runs as $run) {
                self::owns((int) $run['pid'], $run['session']) === false || posix_kill((int) $run['pid'], $signal);
            }
        };
        $left = function () use (&$groups, $runs) {
            return array_filter($groups, fn (int $g) => posix_kill(-$g, 0)) !== []
                || array_filter($runs, fn (array $r) => self::owns((int) $r['pid'], $r['session']) === true) !== [];
        };
        $signal(SIGTERM);
        $deadline = microtime(true) + $grace;
        while ($left() && microtime(true) < $deadline) {
            usleep(100_000);
        }
        if ($left()) {
            $live = array_values(array_filter($groups, fn (int $g) => posix_kill(-$g, 0)));
            $groups = array_values(array_unique([...$groups, ...self::groups([...$pids, ...self::members($live)])]));
            $signal(SIGKILL);
            $deadline = microtime(true) + 2;
            while ($left() && microtime(true) < $deadline) {
                usleep(50_000);
            }
        }
        foreach ($runs as $run) {
            $this->reap($run['session'], $run);
        }

        return $runs;
    }

    /**
     * The process groups of $pids and of all their descendants, never this process's own; without /proc, each pid's
     * group as posix tells it.
     *
     * @param  list<int>  $pids
     * @return list<int>
     */
    private static function groups(array $pids): array
    {
        $table = Processes::table();
        $children = [];
        foreach ($table as $pid => [$parent]) {
            $children[$parent][] = $pid;
        }
        $groups = [];
        for ($queue = $pids, $seen = []; $queue !== [];) {
            $pid = array_shift($queue);
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $group = $table[$pid][1] ?? (posix_getpgid($pid) ?: 0);
            $groups[$group] = true;
            array_push($queue, ...($children[$pid] ?? []));
        }
        unset($groups[0], $groups[1], $groups[posix_getpgrp()]);

        return array_keys($groups);
    }

    /**
     * The processes in $groups now.
     *
     * @param  list<int>  $groups
     * @return list<int>
     */
    private static function members(array $groups): array
    {
        return array_keys(array_filter(Processes::table(), fn (array $p) => in_array($p[1], $groups, true)));
    }

    /**
     * Runs that have ended since the last call: each appended to `runs.jsonl` and its pid file removed.
     *
     * @return list<array<string, mixed>> the run (card, type, session, stage and approval at launch) with its outcome
     */
    public function ended(): array
    {
        $ended = [];
        foreach ($this->pidFiles() as $session => $run) {
            if (! self::alive((int) ($run['pid'] ?? 0), $session) && ($line = $this->reap($session, $run)) !== null) {
                $ended[] = $line;
            }
        }

        return $ended;
    }

    /**
     * Removes the pid file of the ended run of $session and logs the run to `runs.jsonl`; null when another reaper did.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>|null
     */
    private function reap(string $session, array $run): ?array
    {
        // a `stop` and a `kanban run` pass may both find the run ended: the one that removes its pid file logs it
        if (! @unlink($this->paths->runtime("runs/{$session}.pid"))) {
            return null;
        }
        $result = json_decode((string) @file_get_contents($this->paths->runtime("runs/{$session}.json")), true);
        $result = is_array($result) ? $result : [];
        $usage = (array) ($result['usage'] ?? []);
        // a resumed session reports what the whole session cost so far: this run's share is the difference
        $total = (float) ($result['total_cost_usd'] ?? 0);
        $before = $this->sessionCost($session);
        $line = [
            'card' => $run['card'] ?? null, 'type' => $run['type'] ?? null, 'session' => $session,
            'started' => $run['started'] ?? null, 'ended' => Clock::now(), 'turns' => (int) ($result['num_turns'] ?? 0),
            'tokens' => array_sum(array_map('intval', array_intersect_key($usage, array_flip(['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'])))),
            'cost_usd' => round($total >= $before ? $total - $before : $total, 6), 'session_cost_usd' => $total,
            'error' => $result === [] ? 'no result' : (empty($result['is_error']) ? null : (string) ($result['subtype'] ?? 'error')),
            'limit' => self::limit($result),
        ];
        file_put_contents($this->paths->runtime('runs.jsonl'), json_encode($line, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        // an error ends the session without its Stop hook, which would have marked the agent stopped
        $runtime = new Runtime($this->paths);
        if (($agent = $runtime->agent($session)) !== null && empty($agent['stopped_at'])) {
            $runtime->saveAgent(['stopped_at' => Clock::now()] + $agent);
        }

        return $line + ['stage' => $run['stage'] ?? null, 'approved' => $run['approved'] ?? null, 'planned' => $run['planned'] ?? null];
    }

    /**
     * Every run `runs.jsonl` holds, each with its own cost: a line without `session_cost_usd` (logged before 0.8.1) carries
     * its session's running total, from which the run's share is worked out.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Paths $paths): array
    {
        $runs = [];
        $totals = [];
        foreach (@file($paths->runtime('runs.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $run = json_decode($line, true);
            if (! is_array($run)) {
                continue;
            }
            $session = (string) ($run['session'] ?? '');
            $cost = (float) ($run['cost_usd'] ?? 0);
            if (! array_key_exists('session_cost_usd', $run) && $session !== '') {
                $before = $totals[$session] ?? 0.0;
                [$run['session_cost_usd'], $run['cost_usd']] = [$cost, $cost >= $before ? round($cost - $before, 6) : $cost];
            }
            if ($session !== '') {
                $totals[$session] = (float) ($run['session_cost_usd'] ?? $cost);
            }
            $runs[] = $run;
        }

        return $runs;
    }

    /** What $session cost up to its last ended run. */
    private function sessionCost(string $session): float
    {
        $runs = array_filter(self::history($this->paths), fn (array $r) => ($r['session'] ?? null) === $session);

        return $runs === [] ? 0.0 : (float) (end($runs)['session_cost_usd'] ?? 0);
    }

    /** A usage limit or an overloaded API: worth waiting out, not the card's fault. @param  array<string, mixed>  $result */
    public static function limit(array $result): bool
    {
        return ! empty($result['is_error']) && (in_array($result['api_error_status'] ?? null, [429, 529], true)
            || preg_match('/limit|overloaded/i', ($result['subtype'] ?? '').' '.(is_string($result['result'] ?? null) ? $result['result'] : '')) === 1);
    }

    /** @return array<string, array<string, mixed>> */
    private function pidFiles(): array
    {
        $runs = [];
        foreach (glob($this->paths->runtime('runs/*.pid')) ?: [] as $file) {
            $run = json_decode((string) @file_get_contents($file), true);
            $runs[basename($file, '.pid')] = is_array($run) ? $run : [];
        }

        return $runs;
    }

    /** The run of $session goes on: its process is it, or lives where that cannot be told. */
    private static function alive(int $pid, string $session): bool
    {
        return self::owns($pid, $session) ?? ($pid > 0 && posix_kill($pid, 0));
    }

    /**
     * Whether $pid is the run of $session: its command line names the session (setsid and nohup exec, so the pid is
     * claude's). A pid the system gave another process since, or a zombie (no command line), is not. Null without /proc.
     */
    private static function owns(int $pid, string $session): ?bool
    {
        if (! is_dir('/proc/self')) {
            return null;
        }
        $cmdline = $pid > 0 ? @file_get_contents("/proc/{$pid}/cmdline") : false;

        return is_string($cmdline) && $session !== '' && in_array($session, explode("\0", $cmdline), true);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
