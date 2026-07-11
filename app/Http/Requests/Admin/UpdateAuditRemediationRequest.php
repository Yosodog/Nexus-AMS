<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAuditRemediationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-audits') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'due_at' => ['nullable', 'date'],
            'waived_until' => ['nullable', 'date', 'after:now'],
            'remediation_note' => ['nullable', 'string', 'max:500'],
            'clear_waiver' => ['nullable', 'boolean'],
        ];
    }
}
