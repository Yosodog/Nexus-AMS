<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-applications') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'applications_enabled' => ['sometimes', 'boolean'],
            'applications_approved_position_id' => ['required', 'integer', 'min:0'],
            'applications_discord_applicant_role_id' => ['nullable', 'string', 'max:100'],
            'applications_discord_ia_role_id' => ['nullable', 'string', 'max:100'],
            'applications_discord_member_role_id' => ['nullable', 'string', 'max:100'],
            'applications_discord_interview_category_id' => ['nullable', 'string', 'max:100'],
            'applications_approval_announcement_channel_id' => ['nullable', 'string', 'max:100'],
            'applications_approval_message_template' => ['required', 'string'],
        ];
    }
}
