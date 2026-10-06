<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * A card branch whose merge of main carries changes neither side had becomes one commit with the same files on the main
 * it last merged, so the whole change is reviewed as the card's. Its worker runs it in its clone when its stop gate
 * names such a merge; the main session for a card whose agent has stopped. An approval is of the old head: it goes.
 */
#[AsCommand(name: 'kanban:rebuild-branch')]
class RebuildBranchCommand extends Command
{
    protected $signature = 'kanban:rebuild-branch {id : Card id or unique prefix}';

    protected $description = 'Rebuild a card branch as one commit with the same files on main, when a merge of main carries changes of its own';

    protected function perform(): int
    {
        $card = $this->store()->card($this->argument('id'));
        $id = $card->id();
        $inside = (new Context($this->paths(), $this->config()))->worktree($card);
        $cwd = realpath($this->paths()->cwd) ?: $this->paths()->cwd;
        $own = $inside !== null && ($cwd === $inside || str_starts_with($cwd, $inside.'/'));
        if (! $own) {
            $this->requireMainOrOwner('rebuild-branch');
        }
        if (! in_array($card->stage(), ['doing', 'review'], true)) {
            throw new PolicyRefused("{$id} is {$card->stage()}: rebuild-branch takes a card in doing or review");
        }
        $worktrees = new Worktrees($this->paths(), $this->config());
        $path = $inside ?? throw new PolicyRefused("{$id} has no clone on this machine");
        $runtime = new Runtime($this->paths(), $this->store()->snapshot()->staleMinutes());
        foreach ($own ? [] : ['kanban-worker', 'kanban-evaluator'] as $type) {
            if (($agent = $runtime->agentFor($id, $type)) !== null && $runtime->state($agent) === 'live') {
                throw new PolicyRefused("{$id}: its ".substr($type, 7)." is still running; `vendor/bin/kanban wait {$id}`");
            }
        }
        $main = $worktrees->mainBranch();
        if ($worktrees->merging($path) !== null || $worktrees->dirty($path) !== []) {
            throw new PolicyRefused("{$id}: the clone has uncommitted changes or a merge in progress; commit or conclude it first");
        }
        $branch = (string) ($card->work()['branch'] ?? '');
        $git = $worktrees->git($path);
        if ($branch === '' || $git->line(['symbolic-ref', '--short', '-q', 'HEAD']) !== $branch) {
            throw new PolicyRefused("{$id}: the clone is not on its branch {$branch}; `git checkout {$branch}` first");
        }
        $worktrees->sync($path, $branch);
        $from = $worktrees->head('HEAD', $path);
        if (trim($git->attempt(['rev-list', '--merges', '-n', '1', "refs/heads/{$main}..HEAD"])->out) === '') {
            throw new PolicyRefused("{$id}: no merge of {$main} on the branch; nothing to rebuild");
        }
        // the main the branch last merged, so the one commit adds only the card's change and takes back nothing of main's
        $onto = (string) $git->line(['merge-base', 'HEAD', "refs/heads/{$main}"]);
        [$name, $email] = explode("\0", (string) $git->line(['log', '-1', '--format=%an%x00%ae', 'HEAD'])) + [1 => ''];
        $to = trim($git->withEnv(['GIT_AUTHOR_NAME' => $name, 'GIT_AUTHOR_EMAIL' => $email, 'GIT_COMMITTER_NAME' => $name, 'GIT_COMMITTER_EMAIL' => $email])
            ->run(['commit-tree', 'HEAD^{tree}', '-p', $onto, '-m', "{$id}: {$card->title()}\n\nRebuilt from ".substr($from, 0, 7).' on '.$main.' '.substr($onto, 0, 7).' by kanban rebuild-branch: the same files, one commit.']));
        $git->run(['reset', '-q', '--soft', $to]);
        $worktrees->sync($path, $branch);

        $this->store()->update($id, function (array $data) use ($from, $to, $onto) {
            // an approval was of the old head
            $data['work']['approved'] = null;
            $data['log'][] = ['event' => 'rebuilt', 'from' => $from, 'to' => $to, 'onto' => $onto];

            return $data;
        }, $own ? new Actor('worker') : $this->actor());
        $staged = (new Runtime($this->paths()))->stagedFile($id, 'report');
        if (is_file($staged) && @unlink($staged)) {
            $this->say("discarded the report staged for {$id} before the rebuild");
        }
        $this->say("rebuilt {$branch}: ".substr($from, 0, 7).' → '.substr($to, 0, 7).", one commit on {$main} ".substr($onto, 0, 7).' with the same files');
        $this->say($own ? 'next: report again (`vendor/bin/kanban report`)' : 'next: its worker reports again');

        return self::SUCCESS;
    }
}
