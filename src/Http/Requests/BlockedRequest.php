<?php

namespace PetarSpasic\Kanban\Http\Requests;

class BlockedRequest extends KanbanRequest
{
    protected function fields(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
