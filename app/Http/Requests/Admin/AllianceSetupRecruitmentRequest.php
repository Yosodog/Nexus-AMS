<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllianceSetupRecruitmentRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'applications_enabled' => ['required', 'boolean'],
            'approved_position_id' => [Rule::requiredIf($this->boolean('applications_enabled')), 'nullable', 'integer', 'min:1'],
            'approval_message' => [Rule::requiredIf($this->boolean('applications_enabled')), 'nullable', 'string', 'max:2000'],
        ];
    }
}
