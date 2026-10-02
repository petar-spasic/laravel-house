<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\EnvWriter;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Hooks\WorktreeRemove;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:stack')]
class StackCommand extends Command
{
    private const ACTIONS = ['create', 'up', 'down', 'status', 'wait', 'logs', 'url', 'list', 'gc'];

    protected $signature = 'kanban:stack
        {target? : Card id, worktree path or name (default: the worktree of the current directory); or the action}
        {action? : create, up, down, status, wait, logs, url; `stack list`, `stack gc`}
        {--force : gc: also remove unregistered *-wt-* compose projects}';

    protected $description = 'Per-worktree Docker stack: create, up, down, status, wait, logs, url; list and gc machine-wide';

    private Worktrees $worktrees;

    protected function perform(): int
    {
        $this->worktrees = new Worktrees($this->paths(), $this->config());
        [$target, $action] = [$this->argument('target'), $this->argument('action')];
        if ($action === null && in_array($target, self::ACTIONS, true)) {
            [$target, $action] = [null, $target];
        }
        if (! in_array($action, self::ACTIONS, true)) {
            throw new Invalid('action must be one of '.implode(', ', self::ACTIONS));
        }
        if ($action === 'list') {
            return $this->list();
        }
        if ($action === 'gc') {
            return $this->gc();
        }
        [$path, $card] = $this->resolve($target, $action);

        return match ($action) {
            'create' => $this->create($path, $card),
            'up' => $this->up($path, $card),
            'down' => $this->down($path),
            'status' => $this->status($path),
            'wait' => $this->wait($path, $card),
            'logs' => $this->logs($path),
            'url' => $this->url($path),
        };
    }

    /** @return array{0: string, 1: string|null} worktree path and card id */
    private function resolve(?string $target, string $action): array
    {
        if ($target === null) {
            $path = $this->worktrees->containing($this->paths()->cwd)
                ?? throw new Invalid('not inside a code worktree: give a card id or a worktree path');

            return [$path, $this->cardOf($path)];
        }
        if (str_contains($target, '/') || $target === '.' || $target === '..') {
            $path = str_starts_with($target, '/') ? $target : $this->paths()->cwd.'/'.$target;
            $path = realpath($path) ?: $path;

            return [$path, $this->cardOf($path)];
        }
        try {
            $card = $this->store()->card($target);
            $relative = $card->work()['worktree'] ?? null;

            return [$relative !== null ? $this->paths()->main.'/'.$relative : $this->paths()->worktree($card->id(), $card->title()), $card->id()];
        } catch (NotFound $e) {
            $name = EnvWriter::name($target);
            if ($name !== strtolower($target) && $action !== 'create') {
                throw $e;
            }
            $path = $this->paths()->worktrees().'/'.$name;
            if ($action !== 'create' && ! is_dir($path)) {
                throw $e;
            }

            return [realpath($path) ?: $path, null];
        }
    }

    private function cardOf(string $path): ?string
    {
        $relative = $this->paths()->relative($path);
        foreach ($this->store()->snapshot()->cards() as $card) {
            if (($card->work()['worktree'] ?? null) === $relative) {
                return $card->id();
            }
        }

        return null;
    }

    private function requireWorktree(string $path): void
    {
        if (! is_dir($path)) {
            throw new NotFound("no worktree at {$path}");
        }
    }

    private function branch(string $path): ?string
    {
        return $this->worktrees->git($path)->line(['symbolic-ref', '--short', '-q', 'HEAD']);
    }

    private function create(string $path, ?string $card): int
    {
        if (! is_dir($path)) {
            if ($card !== null) {
                throw new PolicyRefused("{$card} has no worktree; `kanban start {$card}` creates it");
            }
            if (dirname($path) !== $this->paths()->worktrees()) {
                throw new PolicyRefused("only worktrees under {$this->paths()->relative($this->paths()->worktrees())}/ are managed");
            }
            $path = $this->worktrees->createNamed(basename($path));
            $this->say("created worktree {$path}");
        }
        $entry = $this->worktrees->prepare($path, $this->branch($path), $card)
            ?? throw new StackFailed('stacks are disabled (stack.compose_file unset or missing)');
        $this->say("worktree {$path}");
        $this->entryLines($entry);

        return self::SUCCESS;
    }

    private function up(string $path, ?string $card): int
    {
        $this->requireWorktree($path);
        $entry = $this->worktrees->up($path, $this->branch($path), $card);
        $this->say("up {$entry['project']}");
        $this->entryLines($entry);

        return self::SUCCESS;
    }

    private function down(string $path): int
    {
        $entry = $this->worktrees->registry()->find($path);
        if ($entry === null) {
            $this->say("no stack registered for {$path}");

            return self::SUCCESS;
        }
        if (! $this->worktrees->down($path)) {
            throw new StackFailed("docker compose down failed for {$entry['project']}; the slot is kept");
        }
        $this->say("down {$entry['project']}; slot {$entry['slot']} released");

        return self::SUCCESS;
    }

    private function status(string $path): int
    {
        $entry = $this->worktrees->registry()->find($path);
        if ($entry === null) {
            $this->say("no stack registered for {$path}");

            return self::SUCCESS;
        }
        $this->entryLines($entry + ['url' => $this->worktrees->env()->url($path, $entry['ports'])]);
        if (is_dir($path)) {
            $ps = $this->worktrees->stack($path, $entry['project'])->compose(['ps'], 60);
            $this->say(rtrim($ps['out']) === '' ? 'containers none' : rtrim($ps['out']));
        }

        return self::SUCCESS;
    }

    /** Polls `http://127.0.0.1:<WEB_PORT><health_path>` until 200; starts the stack first when it is not running. */
    private function wait(string $path, ?string $card): int
    {
        $this->requireWorktree($path);
        $entry = $this->worktrees->registry()->find($path);
        $stack = $entry === null ? null : $this->worktrees->stack($path, $entry['project']);
        if ($stack === null || ! $stack->running()) {
            $entry = $this->worktrees->up($path, $this->branch($path), $card);
            $this->say("up {$entry['project']}");
        }
        $port = $entry['ports']['WEB_PORT'] ?? throw new StackFailed('no WEB_PORT in stack.ports');
        $url = 'http://127.0.0.1:'.$port.$this->setting('stack.health_path', '/up');
        $deadline = microtime(true) + (float) $this->setting('stack.wait_timeout', 110);
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        do {
            $started = microtime(true);
            $body = @file_get_contents($url, false, $context);
            if ($body !== false && preg_match('#^HTTP/\S+\s+200\b#', $http_response_header[0] ?? '')) {
                $this->say("ready {$url}");

                return self::SUCCESS;
            }
            usleep((int) max(0, 1_000_000 - (microtime(true) - $started) * 1_000_000));
        } while (microtime(true) < $deadline);
        $this->say("starting {$url}: not 200 yet; run `kanban stack wait` again");

        return 75;
    }

    private function logs(string $path): int
    {
        $this->requireWorktree($path);
        $entry = $this->worktrees->registry()->find($path) ?? throw new NotFound("no stack registered for {$path}");
        $logs = $this->worktrees->stack($path, $entry['project'])->compose(['logs', '--no-color', '--tail=200'], 60);
        $this->say(rtrim($logs['out'].$logs['err']));

        return $logs['code'] === 0 ? self::SUCCESS : 7;
    }

    private function url(string $path): int
    {
        $entry = $this->worktrees->registry()->find($path) ?? throw new NotFound("no stack registered for {$path}");
        $this->say((string) $this->worktrees->env()->url($path, $entry['ports']));

        return self::SUCCESS;
    }

    private function list(): int
    {
        $entries = $this->worktrees->registry()->all();
        if ($entries === []) {
            $this->say('no stacks registered');
        }
        foreach ($entries as $entry) {
            $ports = array_values($entry['ports']);
            $this->say("slot {$entry['slot']} {$entry['project']} ports ".($ports === [] ? '-' : min($ports).'-'.max($ports))." {$entry['worktree']}"
                .($entry['card'] ? " card {$entry['card']}" : '').(is_dir($entry['worktree']) ? '' : ' [worktree gone]'));
        }

        return self::SUCCESS;
    }

    /** Registry entries whose worktree is gone → down + release; unregistered `-wt-` projects reported (removed with --force). */
    private function gc(): int
    {
        $registry = $this->worktrees->registry();
        $exit = self::SUCCESS;
        $known = [];
        foreach ($registry->all() as $entry) {
            $known[] = $entry['project'];
            if (is_dir($entry['worktree'])) {
                continue;
            }
            $down = Stack::downProject($entry['project'], ['-v', '--remove-orphans', '--rmi', 'local']);
            if ($down['code'] === 0) {
                $registry->release($entry['worktree']);
                $this->say("gc {$entry['project']}: worktree gone, stack down, slot {$entry['slot']} released");
            } else {
                $this->fault("gc {$entry['project']}: down failed, slot kept: ".Worktrees::tail($down['err']));
                $exit = 7;
            }
        }
        foreach (Stack::projects() as $project) {
            if (! str_contains($project, '-wt-') || in_array($project, $known, true)) {
                continue;
            }
            if (! $this->option('force')) {
                $this->say("unregistered {$project} (remove with `kanban stack gc --force`)");

                continue;
            }
            $down = Stack::downProject($project, (array) $this->setting('stack.down', []));
            if ($down['code'] === 0) {
                $this->say("removed {$project}");
            } else {
                $this->fault("remove {$project} failed: ".Worktrees::tail($down['err']));
                $exit = 7;
            }
        }
        if ($this->paths()->inRepo) {
            $reclaimed = (new WorktreeRemove($this->paths(), $this->config()))->reclaim(max: PHP_INT_MAX, budget: 600.0);
            $reclaimed === 0 || $this->say("reclaimed {$reclaimed} idle agent worktree(s)");
            $this->worktrees->prune();
            $this->say('pruned worktrees');
        }

        return $exit;
    }

    /** @param  array<string, mixed>  $entry */
    private function entryLines(array $entry): void
    {
        $this->say("stack {$entry['project']} slot {$entry['slot']}".(($entry['url'] ?? null) ? " {$entry['url']}" : ''));
        $this->say('ports '.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($entry['ports']), $entry['ports'])));
    }
}
