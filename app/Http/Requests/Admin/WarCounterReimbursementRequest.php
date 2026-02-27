<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class WarCounterReimbursementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-accounts') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'gasoline' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'munitions' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'steel' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'aluminum' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'unit_loss_cost' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'infra_loss_cost' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required' => 'Select an account to receive this reimbursement.',
            'gasoline.min' => 'Gasoline reimbursement cannot be negative.',
            'munitions.min' => 'Munitions reimbursement cannot be negative.',
            'steel.min' => 'Steel reimbursement cannot be negative.',
            'aluminum.min' => 'Aluminum reimbursement cannot be negative.',
            'unit_loss_cost.min' => 'Military loss cost cannot be negative.',
            'infra_loss_cost.min' => 'Infrastructure loss cost cannot be negative.',
        ];
    }
}
