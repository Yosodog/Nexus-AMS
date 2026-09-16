<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecruitmentSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-recruitment') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recruitment_enabled' => ['sometimes', 'boolean'],
            'follow_up_enabled' => ['sometimes', 'boolean'],
            'follow_up_subject' => ['required', 'string', 'max:50'],
            'follow_up_message' => ['required', 'string'],
            'primary_subject' => ['nullable', 'string', 'max:50'],
            'primary_message' => ['nullable', 'string'],
        ];
    }

    /**
     * Get the custom error messages for the defined rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'follow_up_subject.required' => 'A follow-up subject line is required.',
            'follow_up_subject.max' => 'The follow-up subject may not exceed 50 characters.',
            'follow_up_message.required' => 'Follow-up message content is required.',
        ];
    }
}
