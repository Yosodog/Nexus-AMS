<?php

namespace App\Http\Requests\Milcom;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveObjectiveRequest extends FormRequest
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
            'override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'force_partial' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'override_reason' => $this->filled('override_reason')
                ? trim((string) $this->input('override_reason'))
                : null,
            'force_partial' => $this->boolean('force_partial'),
        ]);
    }
}
