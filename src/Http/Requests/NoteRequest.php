<?php

namespace PetarSpasic\Kanban\Http\Requests;

class NoteRequest extends KanbanRequest
{
    protected function fields(): array
    {
        return ['text' => ['required', 'string', 'max:5000']];
    }
}
