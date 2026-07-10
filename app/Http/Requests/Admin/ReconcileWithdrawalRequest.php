<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileWithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-accounts') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resolution' => [
                'required',
                Rule::in(['confirmed_sent', 'confirmed_not_sent']),
            ],
            'evidence' => ['required', 'string', 'min:20', 'max:2000'],
            'bank_record_id' => [
                Rule::requiredIf($this->input('resolution') === 'confirmed_sent'),
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.required' => 'Reconciliation evidence is required before this withdrawal can be resolved.',
            'evidence.min' => 'Describe the evidence used to verify the upstream bank outcome.',
            'bank_record_id.required' => 'The Politics & War bank record ID is required when confirming a sent withdrawal.',
        ];
    }
}
