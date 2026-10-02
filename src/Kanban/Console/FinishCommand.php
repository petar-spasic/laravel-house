<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MainCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'kanban:finish')]
class FinishCommand extends Command
{
    protected $signature = 'kanban:finish
        {id : Card id or unique prefix}
        {--force : Merge while main is red (finish.check failed after an earlier merge)}';

    protected $description = 'Merge an approved card into main, mark it done, then tear down its stack, worktree and branch';

    protected function perform(): int
    {
        $this->requireMainOrOwner('finish');
        $worktrees = new Worktrees($this->paths(), $this->config());
        $worktrees->requireMain();
        (new Lease($this->paths()))->acquire($this->actor());
        $snapshot = $this->store()->snapshot();
        $card = $snapshot->resolve($this->argument('id'));
        $id = $card->id();
        $work = $card->work() ?? [];
        $main = $worktrees->mainBranch();

        if ($card->stage() !== 'review') {
            throw new PolicyRefused("{$id} is {$card->stage()}, not review");
        }
        $branch = (string) ($work['branch'] ?? '');
        if ($branch === '' || ! $worktrees->branchExists($branch)) {
            throw new PolicyRefused("{$id}: branch '{$branch}' does not exist");
        }
        $head = $worktrees->head('refs/heads/'.$branch);
        $approved = $work['approved'] ?? null;
        if (! is_array($approved) || ($approved['head'] ?? null) !== $head) {
            throw new PolicyRefused("{$id}: no approval for the branch head ".substr($head, 0, 7).(is_array($approved) ? ' (approved '.substr((string) ($approved['head'] ?? '?'), 0, 7).')' : '').'; run the evaluator again');
        }
        $path = $this->paths()->main.'/'.($work['worktree'] ?? '');
        if (isset($work['worktree']) && is_dir($path) && ($dirty = $worktrees->dirty($path)) !== []) {
            throw new PolicyRefused("{$id}: the worktree has uncommitted changes", $dirty);
        }
        if (($agent = $this->agent($id, $snapshot)) !== null && $agent !== 'stopped' && ! str_starts_with($agent, 'stale')) {
            throw new PolicyRefused("{$id}: an agent is still bound to the card ({$agent}); wait for it to stop");
        }

        $check = new MergeCheck($worktrees->git(), $main);
        $base = (string) ($approved['base'] ?? $work['base'] ?? '');
        if ($base !== '' && ($overlap = $check->movedOverlap($base, $branch)) !== []) {
            throw new Conflict("{$id}: {$main} moved since approval and changed files the branch changes; `kanban refresh {$id}` and re-verify", $overlap);
        }
        if (($conflicts = $check->conflicts($branch)) !== null) {
            $this->transitions()->sendBack($id, 'refresh', $this->actor(), 'merge into '.$main.' conflicts in '.implode(', ', $conflicts));
            throw new Conflict("{$id}: the branch does not merge cleanly into {$main}; {$id} → doing, `kanban refresh {$id}` hands the conflict to the worker", $conflicts);
        }
        if (($markers = $check->markers('refs/heads/'.$main, 'refs/heads/'.$branch)) !== []) {
            $this->transitions()->sendBack($id, 'move', $this->actor(), 'leftover conflict markers: '.implode(', ', array_slice($markers, 0, 5)));
            throw new PolicyRefused("{$id}: the branch holds leftover conflict markers; {$id} → doing, the worker resolves them and reports again", explode("\n", MergeCheck::markersMessage($markers)));
        }
        $files = $check->branchFiles($branch);
        if (($uncommitted = $check->uncommittedOverlap($files)) !== []) {
            throw new PolicyRefused("{$id}: the main checkout has uncommitted changes to files the branch changes; commit or stash them first", $uncommitted);
        }

        $mainCheck = new MainCheck($this->paths());
        $this->requireGreen($mainCheck, $id);

        $git = $worktrees->git();
        $merge = $git->attempt(['merge', '--no-ff', '-m', "{$id}: {$card->title()}", 'refs/heads/'.$branch]);
        if (! $merge->ok()) {
            $git->attempt(['merge', '--abort']);
            throw new Conflict("{$id}: git merge failed and was aborted: ".Worktrees::tail($merge->err ?: $merge->out));
        }
        $sha = $worktrees->head('HEAD');
        $this->say("merged {$id} into {$main} ".substr($sha, 0, 7));
        $this->transitions()->finish($id, $sha, $this->actor());
        $this->say("{$id} review→done");

        if (($untracked = $check->untracked()) !== []) {
            $this->say("warning: {$main} has untracked files (an agent's leftovers?): ".implode(', ', array_slice($untracked, 0, 10))
                .(count($untracked) > 10 ? ' … '.(count($untracked) - 10).' more' : '').'; remove or commit them');
        }

        $exit = $this->afterMerge($worktrees, $files, $mainCheck, $card, $sha);
        $compose = $this->setting('stack.compose_file') ?? 'docker-compose.yml';
        if (($rebuild = MergeCheck::rebuildFiles($files, $compose)) !== []) {
            $this->say('rebuild main: '.implode(', ', $rebuild)." changed; run `docker compose -f {$compose} up -d --build --force-recreate`");
        }

        if ($worktrees->down($path, $work['stack']['project'] ?? null)) {
            $this->say(isset($work['stack']['project']) ? "stack down {$work['stack']['project']}; slot released" : 'stack none');
        } else {
            $this->fault("stack down failed for {$path}; the slot is kept until `kanban stack gc`");
            $exit = 7;
        }
        if (isset($work['worktree']) && is_dir($path)) {
            $removed = $git->attempt(['worktree', 'remove', $path]);
            if ($removed->ok()) {
                $this->say("removed worktree {$work['worktree']}");
            } else {
                $this->fault("git worktree remove {$work['worktree']}: ".trim($removed->err));
                $exit = max($exit, 1);
            }
        }
        if ($worktrees->deleteBranch($branch)) {
            $this->say("deleted branch {$branch}");
        } else {
            $this->fault("branch {$branch} kept (git branch -d refused)");
        }
        $worktrees->prune();
        $this->reportPending();

        return $exit;
    }

    /** While main is red, the check runs again first: a pass clears it; still red, only the card filed for it (or --force) merges. */
    private function requireGreen(MainCheck $mainCheck, string $id): void
    {
        if (($red = $mainCheck->red()) === null || $this->option('force') || ($red['card'] ?? null) === $id) {
            return;
        }
        $commands = $this->merged()['finish']['check'];
        if (($failure = $mainCheck->failure(array_map('strval', (array) $commands))) === null) {
            $mainCheck->clear();
            $this->say('main green again: '.($commands === [] ? 'finish.check is empty' : 'finish.check passes'));

            return;
        }
        throw new PolicyRefused("{$id}: main is red since ".substr($red['sha'], 0, 7)." ({$red['after']} merged): `{$failure['command']}` fails"
            .(isset($red['card']) ? "; finish {$red['card']} first" : '').', or pass --force', array_slice(explode("\n", $failure['tail']), -10));
    }

    /**
     * After the merge, in the main checkout: install what a changed lockfile needs, migrate and run `finish.after` (with a
     * stack), then `finish.check`. A failed install skips the rest; a failed check marks main red and files one bug card.
     *
     * @param  list<string>  $files  what the branch changed
     */
    private function afterMerge(Worktrees $worktrees, array $files, MainCheck $mainCheck, Card $card, string $sha): int
    {
        ['migrate' => $migrate, 'finish' => $finish] = $this->merged();
        foreach ($this->installs($files, (array) $finish['install']) as [$dir, $command]) {
            if (! $this->step('install', $command, $dir)) {
                $this->fault("after: skipped, `{$command}` failed; fix it and run the rest by hand");

                return 1;
            }
        }
        $exit = self::SUCCESS;
        if ($worktrees->stackEnabled()) {
            $steps = [...(is_string($migrate) && $migrate !== '' ? [$migrate] : []), ...array_map('strval', (array) $finish['after'])];
            foreach ($steps as $command) {
                if (preg_match('/--class=(\S+)/', $command, $m) && ! is_file($this->paths()->main.'/database/seeders/'.class_basename(str_replace('\\\\', '\\', $m[1])).'.php')) {
                    continue;
                }
                $exit = $this->step('after', $command, $this->paths()->main) ? $exit : 1;
            }
        }
        $commands = array_map('strval', (array) $finish['check']);
        if ($commands === []) {
            return $exit;
        }
        if (($failure = $mainCheck->failure($commands)) === null) {
            $this->say('check: finish.check passes on main'.($mainCheck->red() !== null ? '; main green again' : ''));
            $mainCheck->clear();

            return $exit;
        }
        $red = $mainCheck->red();
        $bug = $red['card'] ?? $this->store()->create($card->board, [
            'type' => 'bug', 'priority' => 'high', 'title' => mb_strimwidth("main red after {$card->id()}: {$failure['command']}", 0, 120, '…'),
            'labels' => array_values(array_filter($card->labels(), fn (string $l) => str_starts_with($l, 'area:'))),
            'body' => "`{$failure['command']}` failed on main (".($failure['exit'] === null ? 'timed out' : "exit {$failure['exit']}").') after '.$card->id()
                .' merged at '.substr($sha, 0, 7).". The next `finish` waits until it passes.\n\n```\n{$failure['tail']}\n```",
        ], $this->actor())->id();
        $mainCheck->markRed(['sha' => $red['sha'] ?? $sha, 'after' => $red['after'] ?? $card->id(), 'command' => $failure['command'],
            'tail' => $failure['tail'], 'card' => $bug, 'at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->fault("check: `{$failure['command']}` ".($failure['exit'] === null ? 'timed out' : "failed (exit {$failure['exit']})")
            ." on main; main is red, {$bug} holds it, and the next finish waits until it passes");
        foreach (array_slice(explode("\n", $failure['tail']), -10) as $line) {
            $this->fault('  '.$line);
        }

        return 1;
    }

    /**
     * `migrate` and `finish` as the merged main states them, so a card that adds a step has it run by its own finish. A
     * project's `finish` replaces the package's whole, so each key it leaves out keeps the package default.
     *
     * @return array{migrate: mixed, finish: array<string, mixed>}
     */
    private function merged(): array
    {
        $package = require dirname(__DIR__, 3).'/config/kanban.php';
        $config = Standalone::config($this->paths()->main);

        return ['migrate' => $config['migrate'] ?? null, 'finish' => (array) ($config['finish'] ?? []) + $package['finish']];
    }

    /**
     * The install command of each changed lockfile, matched by name at any depth and run in the lockfile's directory.
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $install  lockfile name => command
     * @return list<array{0: string, 1: string}>
     */
    private function installs(array $files, array $install): array
    {
        $runs = [];
        foreach ($files as $file) {
            if (isset($install[basename($file)]) && is_file($this->paths()->main.'/'.$file)) {
                $runs[$file] = [dirname($this->paths()->main.'/'.$file), (string) $install[basename($file)]];
            }
        }

        return array_values($runs);
    }

    private function step(string $kind, string $command, string $dir): bool
    {
        $process = Process::fromShellCommandline($command, $dir, null, null, 600);
        $process->run();
        $where = $dir === $this->paths()->main ? '' : ' in '.$this->paths()->relative($dir);
        if ($process->isSuccessful()) {
            $this->say("{$kind}: {$command}{$where} ok");

            return true;
        }
        $this->fault("{$kind}: {$command}{$where} failed (exit {$process->getExitCode()}): ".Worktrees::tail($process->getErrorOutput() ?: $process->getOutput()));

        return false;
    }
}
