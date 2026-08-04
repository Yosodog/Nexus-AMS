<?php

namespace App\Http\Requests\Milcom;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetManualAssignmentRequest extends FormRequest
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
            'friendly_nation_id' => ['required', 'integer', 'exists:nations,id'],
            'lock' => ['sometimes', 'boolean'],
            'override_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lock' => $this->boolean('lock'),
            'override_reason' => trim((string) $this->input('override_reason')),
        ]);
    }
}
