<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\DatabaseSteps;
use PetarSpasic\LaravelHouse\Kanban\Code\MainCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Stack;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'kanban:finish')]
class FinishCommand extends Command
{
    protected $signature = 'kanban:finish
        {id : Card id or unique prefix}
        {--force : Merge anyway, with the owner: while main is red (finish.check failed after an earlier merge), or a branch that changes kanban\'s own files}
        {--ask : A branch that changes kanban\'s own files the owner has not approved: ask the owner on the card (an Open question that blocks it) instead}
        {--no-rebuild : Leave main\'s stack alone when the merge changed its lockfiles, docker files or compose file}';

    protected $description = 'Merge an approved card into main, mark it done, then tear down its stack, worktree and branch';

    /** In the refusal when main moved under the approval: `kanban run` re-evaluates the card. */
    public const MOVED = 'moved since approval';

    /** The exit when the card merged and is done, but a step after the merge failed. */
    public const STEP_FAILED = 10;

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
        $path = $this->paths()->main.'/'.($work['worktree'] ?? '');
        if (isset($work['worktree']) && is_dir($path)) {
            $worktrees->sync($path, $branch === '' ? null : $branch);
        }
        if ($branch === '' || ! $worktrees->branchExists($branch)) {
            throw new PolicyRefused("{$id}: branch '{$branch}' does not exist");
        }
        $head = $worktrees->head('refs/heads/'.$branch);
        $approved = $work['approved'] ?? null;
        if (! is_array($approved) || ($approved['head'] ?? null) !== $head) {
            throw new PolicyRefused("{$id}: no approval for the branch head ".substr($head, 0, 7).(is_array($approved) ? ' (approved '.substr((string) ($approved['head'] ?? '?'), 0, 7).')' : '').'; run the evaluator again');
        }
        if (isset($work['worktree']) && is_dir($path) && ($dirty = $worktrees->changed($path)) !== []) {
            throw new PolicyRefused("{$id}: the worktree has uncommitted changes", $dirty);
        }
        // the report needed a clean clone: untracked files since are what a check left, removed with the clone
        $leftovers = isset($work['worktree']) ? $worktrees->leftovers($path) : null;
        if (($agent = $this->agent($id, $snapshot)) !== null && $agent !== 'stopped' && ! str_starts_with($agent, 'stale')) {
            throw new PolicyRefused("{$id}: an agent is still bound to the card ({$agent}); `vendor/bin/kanban wait {$id}`");
        }

        $check = new MergeCheck($worktrees->git(), $main);
        $base = (string) ($approved['base'] ?? $work['base'] ?? '');
        if ($base !== '' && ($overlap = $check->movedOverlap($base, $branch, array_map('strval', (array) $this->merged()['finish']['overlap_ignore']))) !== []) {
            throw new Conflict("{$id}: {$main} ".self::MOVED." and changed files the branch changes; `kanban refresh {$id}` and re-verify", $overlap);
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
        $blob = fn (string $file) => $check->blob("refs/heads/{$branch}", $file);
        if (($touched = MergeCheck::unapproved($files, MergeCheck::approvals($card), $blob)) !== [] && ! $this->option('force')) {
            if ($this->option('ask')) {
                $this->askOwner($card, $touched, $main, $branch, $head);
            }
            throw new PolicyRefused("{$id} changes files that steer the agents or git: ".implode(', ', $touched)
                .($this->option('ask') ? '; asked the owner on the card (`kanban questions`)' : "; the owner approves them after reading the diff: `kanban allow-steering {$id} ".implode(' ', $touched).'`'));
        }
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
        if ($leftovers !== null) {
            $this->say($leftovers);
        }
        $this->transitions()->finish($id, $sha, $this->actor());
        $this->say("{$id} review→done");

        // the card is done: from here a step that fails, or throws, is named and the next one runs; the exit is 10. The
        // teardown comes before the long steps, so a killed finish leaves no card behind
        $ok = $this->guard('warning', function () use ($check, $main) {
            if (($untracked = $check->untracked()) !== []) {
                $this->say("warning: {$main} has untracked files (an agent's leftovers?): ".implode(', ', array_slice($untracked, 0, 10))
                    .(count($untracked) > 10 ? ' … '.(count($untracked) - 10).' more' : '').'; remove or commit them');
            }

            return true;
        });
        $ok = $this->guard('stack down', function () use ($worktrees, $path, $work) {
            if ($worktrees->down($path, $work['stack']['project'] ?? null)) {
                $this->say(isset($work['stack']['project']) ? "stack down {$work['stack']['project']}; slot released" : 'stack none');

                return true;
            }
            $this->fault("stack down failed for {$path}; the slot is kept until `kanban stack gc`");

            return false;
        }) && $ok;
        $ok = $this->guard('removing the clone', function () use ($worktrees, $path, $work, $leftovers, $branch) {
            if (isset($work['worktree']) && is_dir($path)) {
                $worktrees->remove($path, $leftovers !== null, $branch);
                $this->say("removed worktree {$work['worktree']}");
            }

            return true;
        }) && $ok;
        $ok = $this->guard('deleting the branch', function () use ($worktrees, $branch) {
            $worktrees->deleteBranch($branch) ? $this->say("deleted branch {$branch}") : $this->fault("branch {$branch} kept (git branch -d refused)");
            $worktrees->prune();

            return true;
        }) && $ok;

        // the image the steps run in is the merged one
        $installed = $this->guard('install', fn () => $this->install($files), 'after: skipped, the install step failed; fix it and run the rest by hand');
        $ok = $this->guard('rebuild main', fn () => $this->rebuildMain($files)) && $ok;
        if ($installed) {
            $ok = $this->guard('after', fn () => $this->afterMerge($worktrees, $mainCheck, $card, $sha)) && $ok;
        }
        $ok = $installed && $ok;
        $ok = $this->guard('journal', function () {
            $this->reportPending();

            return true;
        }) && $ok;
        $ok = $this->guard('publish', function () use ($git, $main) {
            $this->publishMain($git, $main);

            return true;
        }) && $ok;

        return $ok ? self::SUCCESS : self::STEP_FAILED;
    }

    /**
     * A step after the merge: what it throws is printed as its failure, so the steps after it still run. A busy board lock
     * too: the card is done, and the teardown, main's steps and the push still have their work to do.
     */
    private function guard(string $kind, callable $step, ?string $then = null): bool
    {
        try {
            return (bool) $step();
        } catch (Throwable $e) {
            $this->fault("{$kind}: ".basename(str_replace('\\', '/', $e::class)).': '.Worktrees::tail($e->getMessage()));
            $then === null || $this->fault($then);

            return false;
        }
    }

    /** Pushes main once `publish.every` merges are not on the remote; a failed push leaves it to `publish`, never failing the finish. */
    private function publishMain(Git $git, string $main): void
    {
        $every = (int) $this->setting('publish.every', 5);
        $push = new MainPush($git, (string) $this->setting('remote', 'origin'), $main);
        if ($every < 1 || ! $push->hasRemote() || ($merges = $push->unpushedMerges()) < $every) {
            return;
        }
        $this->say("{$main}: {$merges} merges not on the remote (publish.every {$every})");
        try {
            $push->push($this->say(...));
        } catch (KanbanException $e) {
            $this->say("{$main}: not pushed ({$e->getMessage()}); run `kanban publish`");
        }
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

    /** After the merge, in the main checkout: what a changed lockfile needs. A failed install skips the installs after it, then migrate, finish.after and finish.check. @param  list<string>  $files  what the branch changed */
    private function install(array $files): bool
    {
        foreach ($this->installs($files, (array) $this->merged()['finish']['install']) as [$dir, $command]) {
            if (! $this->step('install', $command, $dir)) {
                $this->fault("after: skipped, `{$command}` failed; fix it and run the rest by hand");

                return false;
            }
        }

        return true;
    }

    /**
     * With a stack, migrate and run `finish.after` in main's own stack (brought up first) as agents run them in theirs;
     * then `finish.check`, whose failure marks main red and files one bug card.
     */
    private function afterMerge(Worktrees $worktrees, MainCheck $mainCheck, Card $card, string $sha): bool
    {
        $ok = ! $worktrees->stackEnabled() || $this->databaseSteps();
        $commands = array_map('strval', (array) $this->merged()['finish']['check']);
        if ($commands === []) {
            return $ok;
        }
        if (($failure = $mainCheck->failure($commands)) === null) {
            $this->say('check: finish.check passes on main'.($mainCheck->red() !== null ? '; main green again' : ''));
            $mainCheck->clear();

            return $ok;
        }
        $red = $mainCheck->red();
        // main is marked red even when its bug card cannot be filed: the next finish must wait all the same
        try {
            $bug = $red['card'] ?? $this->store()->create($card->board, [
                'type' => 'bug', 'priority' => 'high', 'title' => mb_strimwidth("main red after {$card->id()}: {$failure['command']}", 0, 120, '…'),
                'labels' => [...array_values(array_filter($card->labels(), fn (string $l) => str_starts_with($l, 'area:'))), Applier::MAIN_RED],
                'acceptance' => [mb_strimwidth("`{$failure['command']}` passes on main", 0, Card::MAX_CRITERION, '…')],
                'body' => "`{$failure['command']}` failed on main (".($failure['exit'] === null ? 'timed out' : "exit {$failure['exit']}").') after '.$card->id()
                    .' merged at '.substr($sha, 0, 7).". The next `finish` waits until it passes.\n\n```\n{$failure['tail']}\n```",
            ], $this->actor())->id();
        } catch (Throwable $e) {
            $bug = null;
            $this->fault('check: no bug card filed for the red main: '.$e->getMessage());
        }
        $mainCheck->markRed(['sha' => $red['sha'] ?? $sha, 'after' => $red['after'] ?? $card->id(), 'command' => $failure['command'],
            'tail' => $failure['tail'], 'card' => $bug, 'at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->fault("check: `{$failure['command']}` ".($failure['exit'] === null ? 'timed out' : "failed (exit {$failure['exit']})")
            .' on main; main is red, '.($bug ?? 'no card').' holds it, and the next finish waits until it passes');
        foreach (array_slice(explode("\n", $failure['tail']), -10) as $line) {
            $this->fault('  '.$line);
        }

        return false;
    }

    /** `migrate` and `finish.after` in main's stack, or in the checkout when `.env` names no compose project. */
    private function databaseSteps(): bool
    {
        $main = $this->paths()->main;
        $commands = DatabaseSteps::commands(Standalone::config($main), $main);
        $stack = $this->mainStack();
        if ($commands === []) {
            return true;
        }
        if ($stack !== null && ($up = $stack->compose(['up', '-d', '--wait', '--no-recreate'], 600))['code'] !== 0) {
            $this->fault("after: skipped, main's stack {$stack->project} did not come up: ".Worktrees::tail($up['err'] ?: "exit {$up['code']}"));

            return false;
        }
        $ok = true;
        foreach ($commands as $command) {
            $ok = $this->step('after', $command, $main, $stack) && $ok;
        }

        return $ok;
    }

    private function mainStack(): ?Stack
    {
        $project = DotEnv::parse($this->paths()->main.'/.env')['COMPOSE_PROJECT_NAME'] ?? '';

        return $project === '' ? null : new Stack($this->paths()->main, $project, (array) $this->setting('stack'), $this->paths()->main);
    }

    /**
     * `finish` as the merged main states it, so a card that adds a step has it run by its own finish. A project's `finish`
     * replaces the package's whole, so each key it leaves out keeps the package default.
     *
     * @return array{finish: array<string, mixed>}
     */
    private function merged(): array
    {
        $package = require dirname(__DIR__, 3).'/config/kanban.php';
        $config = Standalone::config($this->paths()->main);

        return ['finish' => (array) ($config['finish'] ?? []) + $package['finish']];
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

    /** $command in $dir, or in $stack's service container. */
    private function step(string $kind, string $command, string $dir, ?Stack $stack = null): bool
    {
        if ($stack !== null) {
            $result = $stack->compose(['exec', '-T', $stack->service(), 'sh', '-c', $command], 600);
        } else {
            $process = Process::fromShellCommandline($command, $dir, null, null, 600);
            try {
                $process->run();
                $result = ['code' => $process->getExitCode() ?? 1, 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
            } catch (ProcessTimedOutException) {
                $result = ['code' => 124, 'out' => $process->getOutput(), 'err' => 'timed out after '.(int) $process->getTimeout().' s'];
            }
        }
        $where = $dir === $this->paths()->main ? '' : ' in '.$this->paths()->relative($dir);
        if ($result['code'] === 0) {
            $this->say("{$kind}: {$command}{$where} ok");

            return true;
        }
        $this->fault("{$kind}: {$command}{$where} failed (exit {$result['code']}): ".Worktrees::tail($result['err'] ?: $result['out']));

        return false;
    }

    /**
     * The owner's question for a branch that changes files steering the agents or git: answered 1, `answer` approves them
     * as they are and the next `finish` merges; 2 sends the card back to its worker. An open one is asked once, and replaced
     * when more files need it; after an answer the next approval asks anew. The log names what was asked: an answer
     * approves nothing else.
     *
     * @param  list<string>  $touched
     */
    private function askOwner(Card $card, array $touched, string $main, string $branch, string $head): void
    {
        // an open question that names every file stands; one that names fewer gives way to a question about them all
        $open = array_values(array_filter(Questions::open($card), fn (array $q) => Questions::steering($q) !== []));
        $covered = $open !== [] && array_diff($touched, Questions::steering($open[0])) === [];
        $question = '## '.Questions::OPEN.' ('.gmdate('Y-m-d').")\n"
            .'The approved change (head '.substr($head, 0, 7).') also edits files that control how the agents or git behave, not the app itself; '
            .'a careless edit there can switch off a check every card must pass, so it is merged only with your yes. The diff: `git diff '
            .$main.'...'.$branch.' -- '.implode(' ', $touched)."`\n"
            .'Example: an edit to config/kanban.php that removes a test from `gates.report` lets every later card skip that test.'."\n"
            .Questions::STEERING.' '.implode(', ', $touched)."\n"
            ."1. Approve — finish merges the card with these changes\n"
            .'2. Send back — its worker reverts them, and the card is reviewed again';
        $this->store()->update($card->id(), function (array $data) use ($question, $open, $covered, $touched) {
            if (! $covered) {
                $body = (string) ($data['body'] ?? '');
                foreach (array_reverse($open) as $old) {
                    $body = Questions::drop($body, $old['n']);
                }
                $data['body'] = trim(rtrim($body)."\n\n".$question);
                $data['log'][] = ['event' => 'steering_asked', 'files' => $touched];
            }
            $data['blocked'] = Card::QUESTION.'merge with changes to files that steer the agents or git?';

            return $data;
        }, $this->actor());
    }

    /**
     * Rebuilds main's stack when the merge changed what its image or compose file is built from: the images first, while
     * the old containers keep serving, then the containers are recreated on them. False when either failed: the merge is
     * done, and the owner runs the printed command.
     *
     * @param  list<string>  $files
     */
    private function rebuildMain(array $files): bool
    {
        $stack = (array) $this->setting('stack');
        $compose = $stack['compose_file'] ?? 'docker-compose.yml';
        if (($rebuild = MergeCheck::rebuildFiles($files, $compose)) === []) {
            return true;
        }
        $command = "docker compose -f {$compose} build && docker compose -f {$compose} up -d --force-recreate --wait";
        $main = $this->mainStack();
        if ($this->option('no-rebuild') || $main === null || ! Stack::enabled($stack, $this->paths()->main)) {
            $this->say('rebuild main: '.implode(', ', $rebuild)." changed; run `{$command}`");

            return true;
        }
        $project = $main->project;
        $this->say('rebuild main: '.implode(', ', $rebuild).' changed; building its images, main keeps serving');
        $build = $main->compose(['build'], 1800);
        if ($build['code'] !== 0) {
            $this->fault('rebuild main failed to build, main runs on its old images: '.Worktrees::tail($build['err'] ?: "exit {$build['code']}")."; run `{$command}`");

            return false;
        }
        $this->say("rebuild main: recreating its containers (`docker compose -p {$project} ps` follows them)");
        $result = $main->compose(['up', '-d', '--force-recreate', '--wait'], 600);
        if ($result['code'] !== 0) {
            $this->fault('rebuild main failed: '.Worktrees::tail($result['err'] ?: "exit {$result['code']}")."; run `{$command}`");

            return false;
        }
        $this->say("rebuilt main's stack {$project}");

        return true;
    }
}
