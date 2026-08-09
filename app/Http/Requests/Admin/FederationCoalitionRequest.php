<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationCoalitionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-coalitions') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'link_id' => ['sometimes', 'required', 'ulid', 'exists:federation_links,id'],
            'role' => ['sometimes', 'required', 'in:admin,member,observer'],
            'reason_code' => ['sometimes', 'required', 'string', 'min:3', 'max:64'],
            'proposal_type' => ['sometimes', 'required', 'in:member.add,member.remove,member.role,coordinator.transfer'],
            'target_installation_id' => ['sometimes', 'nullable', 'ulid'],
            'requested_role' => ['sometimes', 'nullable', 'in:admin,member,observer'],
        ];
    }
}
