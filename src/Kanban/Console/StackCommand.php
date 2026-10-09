<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\EnvWriter;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Code\StackFailed;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Hooks\WorktreeRemove;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'kanban:stack')]
class StackCommand extends Command
{
    private const ACTIONS = ['create', 'up', 'down', 'status', 'wait', 'reload', 'exec', 'logs', 'url', 'list', 'gc'];

    /** Actions that from inside a code worktree reach only that worktree's own stack. */
    private const OWN = ['reload', 'exec'];

    protected $signature = 'kanban:stack
        {target? : Card id, worktree path or name, or `_merge` for the merge stack (default: the worktree of the current directory); or the action}
        {action? : create, up, down, status, wait, reload, exec, logs, url; `stack list`, `stack gc`}
        {args?* : exec: the command, after `--` (`stack <id> exec -- php artisan test`)}
        {--force : gc: also remove unregistered *-wt-* and *-merge-* compose projects}';

    protected $description = 'Per-worktree Docker stack: create, up, down, status, wait, reload, exec, logs, url; list and gc machine-wide';

    private Worktrees $worktrees;

    protected function perform(): int
    {
        $this->worktrees = new Worktrees($this->paths(), $this->config());
        [$target, $action] = [$this->argument('target'), $this->argument('action')];
        if (in_array($target, self::ACTIONS, true) && ! in_array($action, self::ACTIONS, true)) {
            [$target, $action] = [$action, $target];
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
        if (in_array($action, self::OWN, true)) {
            $this->requireOwn($path, $action);
        }

        return match ($action) {
            'create' => $this->create($path, $card),
            'up' => $this->up($path, $card),
            'down' => $this->down($path),
            'status' => $this->status($path),
            'wait' => $this->wait($path, $card),
            'reload' => $this->reload($path, $card),
            'exec' => $this->exec($path),
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
        if ($target === Paths::MERGE_CLONE) {
            return [$this->paths()->mergeClone(), null];
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

    /** From inside a code worktree only its own stack; from the main checkout any card's. */
    private function requireOwn(string $path, string $action): void
    {
        $own = $this->worktrees->containing($this->paths()->cwd);
        if ($own !== null && $own !== (realpath($path) ?: $path)) {
            throw new PolicyRefused("from a worktree, `stack {$action}` acts only on that worktree's own stack");
        }
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
            if ($this->worktrees->isMergeClone($path)) {
                throw new PolicyRefused('the merge queue makes the merge clone '.$this->paths()->relative($path).' at its first merge');
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
        $this->refuseMidMerge($path, 'up');
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
            $ps = $this->worktrees->stack($path, $entry['project'])->compose(['ps', '-a'], 60);
            $this->say(rtrim($ps['out']) === '' ? 'containers none' : rtrim($ps['out']));
        }

        return self::SUCCESS;
    }

    /**
     * Polls `http://127.0.0.1:<WEB_PORT><health_path>` until 200. Starts the stack first when its service is not
     * running, and recreates it when the docker files changed since it came up. Mid-merge it only starts the service's
     * container as it was, and refuses.
     */
    private function wait(string $path, ?string $card): int
    {
        $this->requireWorktree($path);
        $entry = $this->worktrees->registry()->find($path);
        $stack = $entry === null ? null : $this->worktrees->stack($path, $entry['project']);
        $state = $stack?->state();
        if ($this->worktrees->merging($path) !== null) {
            // the agents' git runs in the service's container
            if ($stack === null || ($state === null && $stack->idle() === true)) {
                $entry = $this->worktrees->up($path, $this->branch($path), $card);
                $this->say("up {$entry['project']}");
            } elseif ($state['status'] !== 'running') {
                $start = $stack->start();
                $this->say($start['code'] === 0 ? "started {$entry['project']} as it was" : "start {$entry['project']} failed: ".Worktrees::tail($start['err'] ?: $start['out']));
            }
            $this->refuseMidMerge($path, 'wait');
        }
        if ($stack === null || ! $stack->running() || ($state !== null && $state['status'] !== 'running')) {
            $entry = $this->worktrees->up($path, $this->branch($path), $card);
            $this->say("up {$entry['project']}");
        } elseif (($reloaded = $this->worktrees->freshen($path)) !== null) {
            $entry = $reloaded;
            $this->say("reloaded {$entry['project']}: docker files changed");
        } else {
            $this->worktrees->record($path, $entry['project']);
        }

        return $this->ready($entry);
    }

    /** `up -d --build --force-recreate`, then waits like `wait`. */
    private function reload(string $path, ?string $card): int
    {
        $this->requireWorktree($path);
        $this->refuseMidMerge($path, 'reload');
        $entry = $this->worktrees->up($path, $this->branch($path), $card, recreate: true);
        $this->say("reloaded {$entry['project']}");

        return $this->ready($entry);
    }

    /** `compose exec -T <stack.service> <args>` with the host env stripped; output streamed, the command's exit code returned. */
    private function exec(string $path): int
    {
        $args = array_values(array_map('strval', (array) $this->argument('args')));
        if ($args === []) {
            throw new Invalid('stack exec needs a command: `kanban stack <id> exec -- <command>`');
        }
        $this->requireWorktree($path);
        $entry = $this->worktrees->registry()->find($path) ?? throw new NotFound("no stack registered for {$path}");
        $stack = $this->worktrees->stack($path, $entry['project']);
        $output = $this->output->getOutput();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $result = $stack->compose(['exec', '-T', $stack->service(), ...$args], null,
            fn (string $type, string $chunk) => ($type === Process::ERR ? $errors : $output)->write($chunk, false, OutputInterface::OUTPUT_RAW));

        return $result['code'];
    }

    /** @param  array<string, mixed>  $entry */
    private function ready(array $entry): int
    {
        $url = $this->worktrees->healthUrl($entry);
        if (($last = $this->worktrees->await($entry)) === null) {
            $this->say("ready {$url}");

            return self::SUCCESS;
        }
        $this->say("starting {$url}: {$last}, not 200 yet; run `kanban stack wait` again");

        return 75;
    }

    /** A merge in progress: the stack stays as it is until the merge is concluded (Worktrees::merging). */
    private function refuseMidMerge(string $path, string $action): void
    {
        if (($files = $this->worktrees->merging($path)) === null) {
            return;
        }
        throw new Conflict("a merge of main is in progress in {$path}, and `stack {$action}` never rebuilds the stack mid-merge: "
            .($files === [] ? '' : 'resolve '.implode(', ', $files).' (file tools work while the stack is down), `git add` them and ')
            .'`git commit --no-edit`, then run `vendor/bin/kanban stack wait`');
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
        $main = $this->paths()->main;
        foreach ($registry->all() as $entry) {
            $known[] = $entry['project'];
            // The registry is machine-wide: another repository's stacks are its own gc's business.
            if (($entry['repo'] ?? null) !== $main || is_dir($entry['worktree'])) {
                continue;
            }
            $down = Stack::downProject($entry['project'], ['-v', '--remove-orphans', '--rmi', 'local']);
            if ($down['code'] === 0) {
                $this->worktrees->forget($entry['worktree'], is_string($entry['repo'] ?? null) ? $entry['repo'] : null);
                $registry->release($entry['worktree']);
                $this->say("gc {$entry['project']}: worktree gone, stack down, slot {$entry['slot']} released");
            } else {
                $this->fault("gc {$entry['project']}: down failed, slot kept: ".Worktrees::tail($down['err']));
                $exit = 7;
            }
        }
        $app = $this->worktrees->env()->app();
        foreach (Stack::projects() as $project) {
            if (! (str_starts_with($project, "{$app}-wt-") || str_starts_with($project, "{$app}-merge-")) || in_array($project, $known, true)) {
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
