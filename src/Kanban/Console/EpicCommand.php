<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Epic;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:epic')]
class EpicCommand extends Command
{
    protected $signature = 'kanban:epic
        {epic? : The epic\'s slug, such as passkey-login; without one, every epic with its progress}
        {title? : Epic title}
        {--goal= : What the epic achieves}
        {--done-when=* : A condition that finishes the epic (repeatable; replaces the list)}
        {--order= : Sort order}';

    protected $description = 'List the epics, or create or update one (cards join it with `set ID epic=<slug>`)';

    protected function perform(): int
    {
        $slug = $this->argument('epic');
        $snapshot = $this->store()->snapshot();
        if ($slug === null) {
            foreach ($snapshot->epics() as $epic) {
                $this->say($this->progress($epic, array_filter($snapshot->cards, fn (Card $c) => $c->epic() === $epic->slug)));
            }
            if ($snapshot->epics === []) {
                $this->say('no epics');
            }

            return self::SUCCESS;
        }
        $this->requireMainOrOwner('epic');
        $data = array_filter([
            'title' => $this->argument('title'),
            'goal' => $this->option('goal'),
            'done_when' => $this->option('done-when') === [] ? null : array_values($this->option('done-when')),
        ], fn ($value) => $value !== null);
        if ($this->option('order') !== null) {
            $order = $this->option('order');
            if (! is_numeric($order) || (string) (int) $order !== (string) $order) {
                throw new Invalid('--order must be an integer');
            }
            $data['order'] = (int) $order;
        }
        $existed = $snapshot->epic($slug) !== null;
        $epic = $this->store()->saveEpic($slug, $data, $this->actor());
        $this->say(($existed ? 'updated' : 'created')." epic {$epic->slug}: {$epic->title()}");
        $this->reportPending();

        return self::SUCCESS;
    }

    /** @param  array<string, Card>  $cards */
    private function progress(Epic $epic, array $cards): string
    {
        $done = count(array_filter($cards, fn (Card $c) => $c->stage() === 'done'));
        $counted = count(array_filter($cards, fn (Card $c) => $c->stage() !== 'dropped'));

        return "{$epic->slug} {$done}/{$counted} done {$epic->title()}".($epic->goal() !== '' ? " — {$epic->goal()}" : '');
    }
}
