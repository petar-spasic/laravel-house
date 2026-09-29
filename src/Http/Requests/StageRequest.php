<?php

namespace PetarSpasic\Kanban\Http\Requests;

use Illuminate\Validation\Rule;
use PetarSpasic\Kanban\Store\Stage;

class StageRequest extends KanbanRequest
{
    protected function fields(): array
    {
        return [
            'to' => ['required', Rule::in(array_column(Stage::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
