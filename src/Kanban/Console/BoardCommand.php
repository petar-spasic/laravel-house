<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:board')]
class BoardCommand extends Command
{
    protected $signature = 'kanban:board
        {board : epic/board}
        {title? : Board title}
        {--kind= : work|decisions (new boards)}
        {--order= : Sort order}
        {--wip-doing= : WIP limit for doing}';

    protected $description = 'Create or update a board (its epic is created when missing)';

    protected function perform(): int
    {
        $this->requireMainOrOwner('board');
        $ref = BoardRef::parse($this->argument('board'));
        $existing = $this->store()->snapshot()->board($ref);
        $data = [];
        if ($this->argument('title') !== null) {
            $data['title'] = $this->argument('title');
        }
        if ($this->option('kind') !== null) {
            if ($existing !== null && $existing->kind() !== $this->option('kind')) {
                throw new Invalid("{$ref} is a {$existing->kind()} board; its kind cannot change");
            }
            $data['kind'] = $this->option('kind');
        }
        if ($this->option('order') !== null) {
            $data['order'] = $this->integer('order');
        }
        if ($this->option('wip-doing') !== null) {
            $data['wip'] = array_merge($existing?->data['wip'] ?? [], ['doing' => $this->integer('wip-doing')]);
        }
        $this->store()->saveBoard($ref, $data, $this->actor());
        $this->say(($existing === null ? 'created' : 'updated')." board {$ref}");
        $this->reportPending();

        return self::SUCCESS;
    }

    private function integer(string $option): int
    {
        $value = $this->option($option);
        if (! is_numeric($value) || (string) (int) $value !== (string) $value) {
            throw new Invalid("--{$option} must be an integer");
        }

        return (int) $value;
    }
}
