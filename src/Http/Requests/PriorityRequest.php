<?php

namespace PetarSpasic\Kanban\Http\Requests;

use Illuminate\Validation\Rule;
use PetarSpasic\Kanban\Store\Priority;

class PriorityRequest extends KanbanRequest
{
    protected function fields(): array
    {
        return ['priority' => ['required', Rule::in(array_column(Priority::cases(), 'value'))]];
    }
}
