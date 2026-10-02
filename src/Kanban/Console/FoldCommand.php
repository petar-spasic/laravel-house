<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Fold;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:fold')]
class FoldCommand extends Command
{
    protected $signature = 'kanban:fold
        {from* : Card ids or unique prefixes to fold}
        {--into= : The card that takes them}';

    protected $description = 'Fold backlog or ready cards into one: it takes their text, criteria, labels and dependencies, and they are dropped';

    protected function perform(): int
    {
        $this->requireMainOrOwner('fold');
        $into = $this->option('into') ?? throw new Invalid('--into names the card that takes the others');
        $store = $this->store();
        $snapshot = $store->snapshot();
        $target = $snapshot->resolve($into)->id();
        $from = array_map(fn (string $id) => $snapshot->resolve($id)->id(), $this->argument('from'));

        $result = null;
        $store->batch(function (Snapshot $fresh) use ($from, $target, &$result) {
            $result = Fold::plan($fresh, $from, $target);

            return $result['changes'];
        }, $this->actor(), "{$target} folded ".implode(' ', array_unique($from)));

        $this->say("{$target} folded ".implode(', ', array_unique($from)).": {$result['added']} criteria added"
            .($result['repointed'] === [] ? '' : '; repointed to it: '.implode(', ', $result['repointed'])));
        foreach ($result['notes'] as $note) {
            $this->say($note);
        }
        $this->reportPending();

        return self::SUCCESS;
    }
}
