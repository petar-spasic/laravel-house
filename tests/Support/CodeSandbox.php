<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A Laravel-shaped main checkout with a board, a main `.env`, a compose file and dependencies to copy, driven
 * through `bin/kanban` with the fake `docker` first on PATH and a private machine state dir.
 */
final class CodeSandbox
{
    public readonly string $state;

    public readonly string $docker;

    public readonly string $home;

    /**
     * First port of this sandbox's pool: one per parallel worker (paratest's TEST_TOKEN), so tests running side by side
     * never bind each other's ports; random when run alone, so separate runs and real stacks (21000+) rarely meet.
     */
    public readonly int $base;

    /** Environment every call of this sandbox gets (a machine of twoMachines() syncs its board). @var array<string, string|false> */
    public array $defaults = [];

    /** @var list<self> the sandboxes of the running test, whose servers end with it */
    private static array $live = [];

    /** Host of worktree URLs: taken from the environment's LOCAL_APP_URL, never a machine address in code. */
    public static function lanHost(): string
    {
        return parse_url((string) getenv('LOCAL_APP_URL'), PHP_URL_HOST) ?: 'localhost';
    }

    public function __construct(public readonly Sandbox $sandbox)
    {
        $tmp = Sandbox::tmp();
        $this->state = $tmp.'/state';
        $this->docker = $tmp.'/docker';
        $this->home = $tmp.'/home';
        $worker = getenv('TEST_TOKEN');
        $this->base = 22000 + ($worker === false ? random_int(0, 49) : (int) $worker % 50) * 200;
        mkdir($this->docker, 0775, true);
        mkdir($this->home, 0775, true);
        self::$live[] = $this;
    }

    /**
     * Ends the servers the running test's stacks left up (FAKE_DOCKER_SERVE): a stack never brought down, such as the
     * merge stack, would otherwise keep its ports bound, and the tests after it in this worker would find no free slot.
     */
    public static function endTest(): void
    {
        foreach (self::$live as $sandbox) {
            $sandbox->killServers();
        }
        self::$live = [];
    }

    /** @param  array<string, mixed>  $overrides  merged over the package config (top-level keys, as the project file does) */
    public static function create(array $overrides = []): self
    {
        $sandbox = Sandbox::create();
        $root = $sandbox->root;
        file_put_contents($root.'/.env', implode("\n", [
            'APP_NAME=Acme', 'APP_KEY=base64:c2VjcmV0', 'LOCAL_APP_URL=http://'.self::lanHost().':8011', 'APP_URL=http://localhost:8011',
            'COMPOSE_PROJECT_NAME=acme-local', 'WEB_PORT=8011', 'DB_PORT=5435', 'SESSION_COOKIE=acme-session', '',
        ]));
        file_put_contents($root.'/docker-compose.local.yml', "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices: {}\n");
        @mkdir($root.'/node_modules/left-pad', 0775, true);
        file_put_contents($root.'/node_modules/left-pad/index.js', "module.exports = 1;\n");
        @mkdir($root.'/storage', 0775, true);
        file_put_contents($root.'/storage/secret.log', "main only\n");
        @mkdir($root.'/config', 0775, true);
        $code = new self($sandbox);
        $code->configure($overrides);
        file_put_contents($root.'/app.php', "<?php\n\nreturn 'app';\n");
        $sandbox->install('ACME');
        file_put_contents($root.'/.gitignore', "/vendor/\n/node_modules/\n/storage/\n/.env\n/docs/kanban/\n/.claude/worktrees/\n");
        $sandbox->git('add', '-A');
        $sandbox->git('commit', '-q', '-m', 'app');

        return $code;
    }

    /** Writes the project's config/kanban.php: the package config with test defaults and $overrides (lists replace). */
    public function configure(array $overrides = []): void
    {
        $config = self::merge(require Sandbox::package().'/config/kanban.php', [
            'stack' => ['pool' => ['base' => $this->base, 'block' => 10, 'first' => 1, 'last' => 20], 'max_stacks' => 6,
                'min_mem_available_gib' => 0, 'min_disk_free_gib' => 0, 'max_load_ratio' => 0, 'wait_timeout' => 3],
            'worktrees' => ['host' => null],
            'migrate' => null,
            // the suite: red while a RED file is in the merged tree
            'finish' => ['after' => [], 'check' => ['test ! -e RED']],
        ]);
        // left to KANBAN_SYNC, as in every sandbox: the package file read here would write this process's value in
        unset($config['sync']);
        file_put_contents($this->sandbox->root.'/config/kanban.php', "<?php\n\nreturn ".var_export(self::merge($config, $overrides), true).";\n");
    }

    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) ? self::merge($base[$key], $value) : $value;
        }

        return $base;
    }

    public function root(): string
    {
        return $this->sandbox->root;
    }

    /** @return array<string, string|false> */
    public function env(array $env = []): array
    {
        return $env + $this->defaults + [
            'PATH' => __DIR__.'/FakeDocker:'.getenv('PATH'),
            'FAKE_DOCKER_DIR' => $this->docker,
            'KANBAN_STATE_DIR' => $this->state,
            'HOME' => $this->home,
            'XDG_STATE_HOME' => false,
            'KANBAN_HOST' => false,
        ];
    }

    public function kanban(array $args, array $env = [], ?string $cwd = null): Process
    {
        return $this->sandbox->kanban($args, $this->env($env), $cwd);
    }

    public function ok(array $args, array $env = [], ?string $cwd = null): string
    {
        $process = $this->kanban($args, $env, $cwd);
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('kanban '.implode(' ', $args).' exited '.$process->getExitCode().":\n".$process->getOutput().$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /** `bin/kanban hook <event>` with the payload JSON on stdin. */
    public function hook(string $event, array $payload, array $env = []): Process
    {
        $payload += ['session_id' => 's', 'cwd' => $this->root(), 'hook_event_name' => $event];

        return $this->sandbox->kanban(['hook', $event], $this->env($env), null, json_encode($payload));
    }

    /** @return list<string> docker calls, oldest first */
    public function calls(): array
    {
        $log = $this->docker.'/calls.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    /** @return list<array{args: string, env: array<string, string>}> what each docker call saw of the host's port and compose variables */
    public function composeEnv(): array
    {
        $log = $this->docker.'/env.log';

        return is_file($log) ? array_map(fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($log))))) : [];
    }

    /** @return list<array<string, mixed>> */
    public function stacks(): array
    {
        $file = $this->state.'/stacks.json';

        return is_file($file) ? array_values(json_decode((string) file_get_contents($file), true)['stacks'] ?? []) : [];
    }

    /** The card's worktree: `work.worktree` once started, else where `start` puts it. */
    public function worktree(string $id): string
    {
        $card = $this->sandbox->read($id);
        $relative = $card['work']['worktree'] ?? null;

        return $relative !== null
            ? $this->root().'/'.$relative
            : $this->root().'/.claude/worktrees/'.strtolower(explode('-', $id, 2)[1]).'-'.Worktrees::slug($card['title'], 24);
    }

    /** A ready card, started. */
    public function started(string $title = 'Add login page', array $env = []): string
    {
        $id = $this->sandbox->readyCard($title);
        $this->ok(['start', $id], $env);

        return $id;
    }

    /** A commit in the card's worktree, as the worker makes it. */
    public function commit(string $id, string $file, string $content, string $message = 'work'): string
    {
        $wt = $this->worktree($id);
        @mkdir(dirname($wt.'/'.$file), 0775, true);
        file_put_contents($wt.'/'.$file, $content);
        $this->gitIn($wt, 'add', '-A');
        $this->gitIn($wt, 'commit', '-q', '-m', $message);

        return trim($this->gitIn($wt, 'rev-parse', 'HEAD'));
    }

    /** A commit on main in the main checkout. */
    public function commitMain(string $file, string $content): string
    {
        file_put_contents($this->root().'/'.$file, $content);
        $this->sandbox->git('add', $file);
        $this->sandbox->git('commit', '-q', '-m', "main: {$file}");

        return trim($this->sandbox->git('rev-parse', 'HEAD'));
    }

    /** Puts the card in review with an approval of the branch head (what report + verdict do), committed on the board. */
    public function approve(string $id, ?string $head = null, ?string $at = null): void
    {
        $card = $this->sandbox->read($id);
        $branch = $card['work']['branch'];
        $head ??= trim($this->gitIn($this->worktree($id), 'rev-parse', 'refs/heads/'.$branch));
        $card['stage'] = 'review';
        $card['work']['head'] = $head;
        $card['work']['approved'] = ['head' => $head, 'at' => $at ?? $card['updated']];
        $files = glob($this->root().'/docs/kanban/*/'.$id.'.json');
        Json::write($files[0], Json::encode($card, 'card'));
        $this->sandbox->boardGit('commit', '-q', '-am', "{$id} approved (test)");
    }

    /** The compose file mounting the worktree into its container, so the agents' shells, the gates and the suite run there. */
    public function mountWorktree(): void
    {
        file_put_contents($this->root().'/docker-compose.local.yml', "name: \"\${COMPOSE_PROJECT_NAME:?unset}\"\nservices:\n  app:\n    volumes: ['./:\${KANBAN_WORKTREE_PATH:-/app}']\n");
        $this->sandbox->git('commit', '-q', '-am', 'mount the worktree');
    }

    public function mergeClone(): string
    {
        return $this->root().'/.claude/worktrees/_merge';
    }

    /** @return array<string, mixed>|null the merge lease on this checkout's board */
    public function lease(): ?array
    {
        return json_decode((string) file_get_contents($this->root().'/docs/kanban/kanban.json'), true)['merge'] ?? null;
    }

    /** @return array<string, mixed>|null this checkout's merge.json */
    public function mergeState(): ?array
    {
        $file = $this->root().'/.git/laravel-house/merge.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /**
     * merge-seen.json as if this machine had seen the board's lease, and $heads at the front of the queue, since $ago
     * seconds ago.
     *
     * @param  list<string>  $heads
     */
    public function seen(int $ago, array $heads = []): void
    {
        $now = hrtime(true) / 1e9;
        $lease = $this->lease();
        $seen = ['boot' => trim((string) file_get_contents('/proc/sys/kernel/random/boot_id'))]
            + ($lease === null ? [] : ['lease' => ['id' => $lease['id'], 'beat' => $lease['beat'], 'seen' => $now - $ago]])
            + ['heads' => array_fill_keys($heads, ['since' => $now - $ago])];
        @mkdir($this->root().'/.git/laravel-house', 0775, true);
        file_put_contents($this->root().'/.git/laravel-house/merge-seen.json', json_encode($seen));
    }

    /**
     * Two machines sharing $origin: this sandbox's main and board pushed there, and a clone of it with its own `.env`,
     * docker, state and home; both sync their board.
     */
    public function twoMachines(Origin $origin): self
    {
        $this->sandbox->addRemote($origin);
        $this->defaults = ['KANBAN_SYNC' => 'on'];
        $this->ok(['sync']);
        $clone = $origin->clone('b');
        foreach (['.env', 'node_modules', 'storage'] as $item) {
            (new Process(['cp', '-a', $this->root().'/'.$item, $clone->root.'/'.$item]))->mustRun();
        }
        $other = new self($clone);
        $other->defaults = ['KANBAN_SYNC' => 'on'];
        $other->ok(['attach']);

        return $other;
    }

    public function gitIn(string $dir, string ...$args): string
    {
        $process = new Process(['git', ...$args], $dir, ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C']);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $args).': '.$process->getErrorOutput().$process->getOutput());
        }

        return $process->getOutput();
    }

    public function killServers(): void
    {
        $file = $this->docker.'/state.json';
        foreach (is_file($file) ? json_decode((string) file_get_contents($file), true)['projects'] ?? [] : [] as $project) {
            if (! empty($project['pid'])) {
                @posix_kill((int) $project['pid'], SIGTERM);
            }
        }
    }
}
