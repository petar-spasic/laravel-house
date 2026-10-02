<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
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
        if ($this->option('all')) {
            $cards = $this->store()->snapshot()->cards(fn (Card $c) => Stage::isActive($c->stage()) && isset($c->work()['worktree'])
                && is_dir($this->paths()->main.'/'.$c->work()['worktree']));
        } elseif ($this->argument('id') !== null) {
            $cards = [$this->store()->card($this->argument('id'))];
        } else {
            throw new Invalid('give a card id or --all');
        }

        $exit = self::SUCCESS;
        foreach ($cards as $card) {
            $exit = max($exit, $this->refresh($card, $worktrees));
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
        $git = $worktrees->git($path);
        $before = $worktrees->head('HEAD', $path);
        $merge = $git->attempt(['merge', '--no-edit', $main]);
        $conflicted = array_values(array_filter(explode("\n", trim($git->attempt(['diff', '--name-only', '--diff-filter=U'])->out))));

        if ($conflicted !== []) {
            $note = 'merge of '.$main.' conflicts in '.implode(', ', $conflicted);
            if ($card->stage() === 'review') {
                $this->transitions()->sendBack($id, 'refresh', $this->actor(), $note);
            } else {
                $this->clearApproval($id);
            }
            $this->say("conflict {$id}: merge of {$main} left in progress in {$path}");
            foreach ($conflicted as $file) {
                $this->say("conflicted {$file}");
            }
            $this->say("{$id} → doing");
            $this->say('SendMessage: '.$this->message($id, $main, $conflicted));

            return 5;
        }
        if (! $merge->ok()) {
            $this->fault("refresh {$id}: git merge {$main} failed: ".Worktrees::tail($merge->err ?: $merge->out));

            return 1;
        }
        $after = $worktrees->head('HEAD', $path);
        if ($after === $before) {
            $this->say("up to date {$id}");

            return self::SUCCESS;
        }
        if (($card->work()['approved'] ?? null) !== null) {
            $this->clearApproval($id);
        }
        $this->say("refreshed {$id}: merged {$main} (".substr($before, 0, 7).'..'.substr($after, 0, 7).')'.($card->stage() === 'review' ? '; re-verify before finish' : ''));

        return self::SUCCESS;
    }

    private function clearApproval(string $id): void
    {
        $this->store()->update($id, function (array $data) {
            $data['work']['approved'] = null;

            return $data;
        }, $this->actor());
    }

    /** @param  list<string>  $files */
    private function message(string $id, string $main, array $files): string
    {
        return "Card {$id}: {$main} moved and `git merge {$main}` in your worktree stopped on conflicts in "
            .implode(', ', $files).'. The merge is still in progress: resolve every conflict keeping the intent of both sides, '
            .'run the checks that cover those files, then `git add` them and `git commit --no-edit` to conclude the merge. '
            ."When the branch is green again, report with `vendor/bin/kanban report {$id} --status=review`.";
    }
}
