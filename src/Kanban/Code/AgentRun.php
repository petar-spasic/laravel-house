<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeAgents;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeSettings;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Headless card agents for `kanban run`: each is `claude -p --agent kanban-worker|kanban-evaluator` in main's checkout,
 * with the model and effort of `kanban.agents.<role>` passed as flags and no model or effort inherited from the environment,
 * detached (its own session, so stopping `run` leaves it working), bound to its card by its session id. A run's pid
 * file sits in `runs/` until the run is reaped; its result JSON and stderr beside it; one line per ended run in
 * `runs.jsonl`.
 */
final class AgentRun
{
    public const WORKER = 'kanban-worker';

    public const EVALUATOR = 'kanban-evaluator';

    /** The launching session's model and effort, which would otherwise override the flags or the agent file. */
    private const INHERITED = ['CLAUDE_EFFORT' => false, 'CLAUDE_CODE_EFFORT_LEVEL' => false, 'ANTHROPIC_MODEL' => false, 'CLAUDE_CODE_SUBAGENT_MODEL' => false];

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(private readonly Paths $paths, private readonly array $config) {}

    /** Why `kanban run` cannot drive this checkout, or null. */
    public function unusable(): ?string
    {
        $finder = new ExecutableFinder;

        return match (true) {
            ($this->config['agents']['shell'] ?? 'container') !== 'container' || ($this->config['stack']['compose_file'] ?? null) === null => 'kanban run needs card containers (agents.shell container and stack.compose_file set): a headless agent may run only the commands Guard routes into one',
            $finder->find('setsid') === null => 'kanban run needs setsid (Linux) to detach the agents',
            $finder->find('claude') === null => 'kanban run needs Claude Code: no `claude` on PATH',
            default => null,
        };
    }

    /**
     * Launches a new session for the card, or resumes $resume; returns the session id. $again: a new session on work in
     * progress, for a worker whose own session grew too large to resume.
     */
    public function launch(Card $card, string $type, ?string $resume = null, bool $again = false): string
    {
        $session = $resume ?? self::uuid();
        $worktree = (string) ($card->work()['worktree'] ?? '');
        $worktree = realpath($this->paths->main.'/'.$worktree) ?: $this->paths->main.'/'.$worktree;
        $prompt = match (true) {
            $resume !== null => "Resumed for card {$card->id()}: run vendor/bin/kanban context and act on what it shows (section 5 of your instructions).",
            $again => "Card {$card->id()}. Worktree {$worktree} — a new session on work in progress: orient (section 1), then act on what vendor/bin/kanban context shows (section 5).",
            default => "Card {$card->id()}. Worktree {$worktree}",
        };
        (new Runtime($this->paths))->saveAgent(['agent_id' => $session, 'agent_type' => $type, 'card' => $card->id(), 'worktree' => $worktree,
            'bound_at' => Clock::now(), 'started_at' => Clock::now(), 'stopped_at' => null, 'stop_blocks' => 0, 'headless' => true]);

        $tools = array_values((array) ($this->config['agents']['allowed_tools'] ?? []));
        // Guard's routed commands, allowed here and not through settings.local.json, which an untrusted checkout ignores
        $main = realpath($this->paths->main) ?: $this->paths->main;
        $allow = ['permissions' => ['allow' => ['Bash('.Guard::kanban($main).' *)', ClaudeSettings::execPermission($main)]]];
        $pinned = [];
        foreach (['model', 'effort'] as $key) {
            if (($value = ClaudeAgents::setting($this->config, $type, $key)) !== null) {
                array_push($pinned, "--{$key}", $value);
            }
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
        file_put_contents("{$dir}/{$session}.pid", json_encode([
            'pid' => (int) trim($process->getOutput()), 'card' => $card->id(), 'type' => $type, 'started' => Clock::now(),
            'stage' => $card->stage(), 'approved' => $card->work()['approved']['head'] ?? null,
        ]));

        return $session;
    }

    /** @return array<string, array<string, mixed>> runs still going, by session */
    public function running(): array
    {
        $runs = [];
        foreach ($this->pidFiles() as $session => $run) {
            if (self::alive((int) ($run['pid'] ?? 0))) {
                $runs[$session] = $run;
            }
        }

        return $runs;
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
            if (self::alive((int) ($run['pid'] ?? 0))) {
                continue;
            }
            $result = json_decode((string) @file_get_contents($this->paths->runtime("runs/{$session}.json")), true);
            $result = is_array($result) ? $result : [];
            $usage = (array) ($result['usage'] ?? []);
            $line = [
                'card' => $run['card'] ?? null, 'type' => $run['type'] ?? null, 'session' => $session,
                'started' => $run['started'] ?? null, 'ended' => Clock::now(), 'turns' => (int) ($result['num_turns'] ?? 0),
                'tokens' => array_sum(array_map('intval', array_intersect_key($usage, array_flip(['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'])))),
                'cost_usd' => (float) ($result['total_cost_usd'] ?? 0),
                'error' => $result === [] ? 'no result' : (empty($result['is_error']) ? null : (string) ($result['subtype'] ?? 'error')),
                'limit' => self::limit($result),
            ];
            file_put_contents($this->paths->runtime('runs.jsonl'), json_encode($line, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
            @unlink($this->paths->runtime("runs/{$session}.pid"));
            // an error ends the session without its Stop hook, which would have marked the agent stopped
            $runtime = new Runtime($this->paths);
            if (($agent = $runtime->agent($session)) !== null && empty($agent['stopped_at'])) {
                $runtime->saveAgent(['stopped_at' => Clock::now()] + $agent);
            }
            $ended[] = $line + ['stage' => $run['stage'] ?? null, 'approved' => $run['approved'] ?? null];
        }

        return $ended;
    }

    /**
     * What each turn of a resumed $session reads again: its last run's tokens per turn, a measure of the session's size
     * (a turn reads the whole session, mostly from the cache). Null when no run of it has ended.
     */
    public function context(string $session): ?int
    {
        $last = null;
        foreach (@file($this->paths->runtime('runs.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_contains($line, $session) && is_array($run = json_decode($line, true)) && ($run['session'] ?? null) === $session) {
                $last = $run;
            }
        }

        return $last === null || (int) ($last['turns'] ?? 0) < 1 ? null : intdiv((int) ($last['tokens'] ?? 0), (int) $last['turns']);
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

    private static function alive(int $pid): bool
    {
        return $pid > 0 && posix_kill($pid, 0);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
