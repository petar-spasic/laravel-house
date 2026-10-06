<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:refresh')]
class RefreshCommand extends Command
{
    protected $signature = 'kanban:refresh
        {id? : Card id or unique prefix}
        {--all : Every doing or review card with a worktree on this machine}';

    protected $description = 'Merge main into a card branch; a conflict is left in progress and handed back to the worker';

    protected function perform(): int
    {
        $this->requireMainOrOwner('refresh');
        $worktrees = new Worktrees($this->paths(), $this->config());
        (new Lease($this->paths()))->acquire($this->actor());
        $snapshot = $this->store()->snapshot();
        if ($this->option('all')) {
            $cards = $snapshot->cards(fn (Card $c) => Stage::isActive($c->stage()) && isset($c->work()['worktree'])
                && is_dir($this->paths()->main.'/'.$c->work()['worktree']));
        } elseif ($this->argument('id') !== null) {
            $cards = [$this->store()->card($this->argument('id'))];
        } else {
            throw new Invalid('give a card id or --all');
        }

        $exit = self::SUCCESS;
        foreach ($cards as $card) {
            if (($live = $this->live($card, $snapshot)) !== null) {
                if (! $this->option('all')) {
                    $failed = str_starts_with((new Runtime($this->paths()))->refusal($card->id(), $live === 'worker' ? 'report' : 'verdict')['reason'] ?? '', 'hook failed');
                    throw new PolicyRefused("{$card->id()}: its {$live} is still running (what it stages applies when it stops); `vendor/bin/kanban wait {$card->id()}`"
                        .($failed ? '; its stop hook failed, so `vendor/bin/kanban apply` settles it' : ''));
                }
                $this->say("skipped {$card->id()}: {$live} live");

                continue;
            }
            try {
                $exit = max($exit, $this->refresh($card, $worktrees));
            } catch (PolicyRefused $e) {
                if (! $this->option('all')) {
                    throw $e;
                }
                $this->say("skipped {$card->id()}: ".strtok($e->getMessage(), "\n"));
                $exit = max($exit, PolicyRefused::EXIT);
            }
        }

        return $exit;
    }

    private function refresh(Card $card, Worktrees $worktrees): int
    {
        $id = $card->id();
        $relative = $card->work()['worktree'] ?? null;
        $path = $relative === null ? null : $this->paths()->main.'/'.$relative;
        if (! Stage::isActive($card->stage()) || $path === null || ! is_dir($path)) {
            throw new NotFound("{$id} has no worktree on this machine");
        }
        $main = $worktrees->mainBranch();
        // a merge would carry the edits into the merge commit, where nobody reviews them as the card's change
        if (($dirty = $worktrees->dirty($path)) !== []) {
            throw new PolicyRefused($worktrees->merging($path) !== null
                ? "{$id}: a merge of {$main} is in progress in its clone; its worker concludes it first"
                : "{$id}: uncommitted changes in its clone; its worker commits them before {$main} is merged in", array_slice($dirty, 0, 20));
        }
        $git = $worktrees->git($path);
        $worktrees->sync($path, $card->work()['branch'] ?? null);
        $before = $worktrees->head('HEAD', $path);
        $merge = $git->attempt(['merge', '--no-edit', $main]);
        $migrations = array_values(array_filter(explode("\n", trim($git->attempt(['diff', '--name-only', '--diff-filter=A', "{$before}...refs/heads/{$main}", '--', 'database/migrations/'])->out))));
        $arrived = $migrations === [] ? null : count($migrations)." migration(s) arrived from {$main}: the card's agent runs the `database` commands `kanban context` prints";
        $conflicted = array_values(array_filter(explode("\n", trim($git->attempt(['diff', '--name-only', '--diff-filter=U'])->out))));

        if ($conflicted !== []) {
            $this->round($card, ['from' => $before, 'conflicts' => $conflicted], 'merge of '.$main.' conflicts in '.implode(', ', $conflicted));
            $this->say("conflict {$id}: merge of {$main} left in progress in {$path}");
            foreach ($conflicted as $file) {
                $this->say("conflicted {$file}");
            }
            $this->say("{$id} → doing");
            if (is_string($project = $card->work()['stack']['project'] ?? null)) {
                $this->say("stack {$project} serves the conflicted tree until the worker concludes the merge; run no checks against it");
            }
            if ($arrived !== null) {
                $this->say($arrived);
            }
            $this->say('SendMessage: '.$this->message($id, $main, $conflicted));
            $this->say('spawn (when its worker has stopped): '.Worktrees::spawnLine($card, 'kanban-worker', $path));

            return 5;
        }
        if (! $merge->ok()) {
            $this->fault("refresh {$id}: git merge {$main} failed: ".Worktrees::tail($merge->err ?: $merge->out));

            return 1;
        }
        $after = $worktrees->head('HEAD', $path);
        $worktrees->sync($path, $card->work()['branch'] ?? null);
        if ($after === $before) {
            $this->say("up to date {$id}");
            $this->spawn($card, $path);

            return self::SUCCESS;
        }
        $this->round($card, ['from' => $before, 'head' => $after]);
        $this->say("refreshed {$id}: merged {$main} (".substr($before, 0, 7).'..'.substr($after, 0, 7).')'.($card->stage() === 'review' ? '; re-verify before finish' : ''));
        if ($arrived !== null) {
            $this->say($arrived);
        }
        $rebuild = MergeCheck::rebuildFiles(array_values(array_filter(explode("\n", trim($git->attempt(['diff', '--name-only', "{$before}...{$after}"])->out)))),
            $this->config()['stack']['compose_file'] ?? null);
        if ($rebuild !== [] && ($entry = $worktrees->freshen($path)) !== null) {
            $this->say("reloaded {$entry['project']}: ".implode(', ', $rebuild).' changed');
        }
        $this->spawn($card, $path);

        return self::SUCCESS;
    }

    /** The agent the card in its stage takes next: its worker in doing, an evaluator in review. */
    private function spawn(Card $card, string $path): void
    {
        $this->say('spawn: '.Worktrees::spawnLine($card, $card->stage() === 'review' ? 'kanban-evaluator' : 'kanban-worker', $path));
    }

    /** An agent of the card that has not stopped (`worker`, `evaluator`), or null. */
    private function live(Card $card, Snapshot $snapshot): ?string
    {
        $runtime = new Runtime($this->paths(), $snapshot->staleMinutes());
        foreach (['kanban-worker', 'kanban-evaluator'] as $type) {
            if (($agent = $runtime->agentFor($card->id(), $type)) !== null && $runtime->state($agent) === 'live') {
                return str_replace('kanban-', '', $type);
            }
        }

        return null;
    }

    /**
     * Records the round boundary in one write: a `refresh` log entry, the approval cleared, and on a conflict the card back
     * in doing. A report staged for the old head is discarded.
     *
     * @param  array<string, mixed>  $entry
     */
    private function round(Card $card, array $entry, ?string $conflict = null): void
    {
        $this->store()->update($card->id(), function (array $data) use ($entry, $conflict) {
            $data['work']['approved'] = null;
            $data['log'][] = ['event' => 'refresh', ...$entry];

            return $conflict !== null && $data['stage'] === 'review' ? Transitions::stage($data, 'doing', 'refresh', $conflict) : $data;
        }, $this->actor());
        $staged = (new Runtime($this->paths()))->stagedFile($card->id(), 'report');
        if (is_file($staged) && @unlink($staged)) {
            $this->say("discarded the report staged for {$card->id()} before the merge");
        }
    }

    /** @param  list<string>  $files */
    private function message(string $id, string $main, array $files): string
    {
        return "Card {$id}: {$main} moved; a merge of {$main} into your branch is in progress in your worktree, with conflicts in "
            .implode(', ', $files).'. Resolve each conflict by keeping both sides\' content and adding nothing neither side had, '
            .'then `git add` the files and `git commit --no-edit` to conclude the merge. After it, run `vendor/bin/kanban stack wait`, the `database` commands '
            ."`vendor/bin/kanban context` lists, `vendor/bin/kanban gates` and the whole test suite, then report with `vendor/bin/kanban report {$id} --status=review`.";
    }
}
