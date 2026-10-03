<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Creation;
use PetarSpasic\LaravelHouse\Kanban\Policy\Shape;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:new')]
class NewCommand extends Command
{
    protected $signature = 'kanban:new
        {board : The board, such as work}
        {title : Card title}
        {--type= : feature|bug|chore|spike}
        {--priority= : urgent|high|normal|low}
        {--epic= : The epic the card belongs to (its slug)}
        {--label=* : Label (repeatable)}
        {--accept=* : Acceptance criterion (repeatable)}
        {--depends=* : Card id it depends on (repeatable)}
        {--body= : Markdown body}
        {--body-file= : Read the body from a file (- = stdin)}
        {--stage= : Initial stage: backlog|ready}';

    protected $description = 'Create a card';

    protected function perform(): int
    {
        $this->requireMainOrOwner('new');
        $store = $this->store();
        $ref = BoardRef::parse($this->argument('board'));
        $body = $this->option('body-file') !== null ? $this->readFile($this->option('body-file')) : $this->option('body');
        $fields = (new Creation(Creation::CLI))->fields($store->snapshot(), $ref, [
            'title' => $this->argument('title'),
            'type' => $this->option('type'),
            'priority' => $this->option('priority'),
            'epic' => $this->option('epic'),
            'body' => $body,
            'labels' => $this->option('label'),
            'accept' => $this->option('accept'),
            'depends' => $this->option('depends'),
            'stage' => $this->option('stage'),
        ]);

        $card = $store->create($ref, $fields, $this->actor());
        $this->say("created {$card->id()} {$card->board} {$card->stage()}");
        if ($card->areas() !== [] || $card->dependsOn() !== []) {
            foreach (Shape::hints($store->snapshot(), $card, $card->areas(), $card->dependsOn()) as $hint) {
                $this->say($hint);
            }
        }
        $this->reportPending();

        return self::SUCCESS;
    }

    private function readFile(string $file): string
    {
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($content === false) {
            throw new NotFound("cannot read {$file}");
        }

        return $content;
    }
}
