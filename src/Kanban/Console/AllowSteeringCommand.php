<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The owner's approval, in the card's log, of changes to files that steer the agents or git (MergeCheck::PROTECTED):
 * `finish` merges a card whose branch changes only approved ones. Given at planning for a change a criterion asks for, it
 * covers any change; given after reading the diff, the files as they are then.
 */
#[AsCommand(name: 'kanban:allow-steering')]
class AllowSteeringCommand extends Command
{
    protected $signature = 'kanban:allow-steering
        {id : Card id or unique prefix}
        {paths* : Files (or directories ending in /) that steer the agents or git, e.g. config/kanban.php}';

    protected $description = 'Approve a card\'s changes to files that steer the agents or git, so finish merges them';

    protected function perform(): int
    {
        $this->requireMainOrOwner('allow-steering');
        $paths = array_values(array_unique(array_map(fn (string $p) => (string) preg_replace('#^\./#', '', trim($p)), (array) $this->argument('paths'))));
        $other = array_values(array_filter($paths, fn (string $p) => MergeCheck::protected([str_ends_with($p, '/') ? $p.'x' : $p]) === []));
        if ($other !== []) {
            throw new Invalid(implode(', ', $other).': not a file that steers the agents or git ('.implode(', ', MergeCheck::PROTECTED).'); finish needs no approval for it');
        }
        $card = $this->store()->card($this->argument('id'));
        $approval = MergeCheck::approvalOf(new Worktrees($this->paths(), $this->config()), $this->paths()->main, $card, $paths);
        $card = $this->store()->update($card->id(), function (array $data) use ($approval) {
            $data['log'][] = $approval;

            return $data;
        }, $this->actor());
        $this->say("{$card->id()}: finish merges its changes to ".implode(', ', $paths)
            .(isset($approval['blobs']) ? ' as they are now ('.implode(', ', array_keys($approval['blobs'])).'); a later change to those asks again' : ''));

        return self::SUCCESS;
    }
}
