<?php

namespace App\Http\Requests\Milcom;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BatchObjectivesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'generation_version' => ['required', 'integer', 'min:1'],
            'objective_ids' => ['required', 'array', 'min:1', 'max:500'],
            'objective_ids.*' => ['integer', 'distinct', 'exists:milcom_objectives,id'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ];
    }
}
