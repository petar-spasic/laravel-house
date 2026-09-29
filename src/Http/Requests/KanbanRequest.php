<?php

namespace PetarSpasic\Kanban\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use PetarSpasic\Kanban\Store\Rev;

/** A board write from the UI: every one carries the rev of the card it was rendered from. */
abstract class KanbanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    abstract protected function fields(): array;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->fields() + ['rev' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/']];
    }

    public function rev(): Rev
    {
        return new Rev((string) $this->validated('rev'));
    }

    public function wantsFragment(): bool
    {
        return $this->header('X-Kanban') === 'fragment';
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $this->wantsFragment()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('kanban::partials.notice', [
            'message' => 'Not saved',
            'details' => $validator->errors()->all(),
            'error' => true,
        ], 422));
    }
}
