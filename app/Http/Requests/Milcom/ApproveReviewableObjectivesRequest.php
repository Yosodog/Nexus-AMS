<?php

namespace App\Http\Requests\Milcom;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveReviewableObjectivesRequest extends FormRequest
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
            'override_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'override_reason.required' => 'Explain why these warnings are acceptable before approving them.',
            'override_reason.min' => 'Give a little more detail about why these warnings are acceptable.',
        ];
    }
}
