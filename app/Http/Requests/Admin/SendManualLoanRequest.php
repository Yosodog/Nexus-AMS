<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendManualLoanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-loans') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:1', 'decimal:0,2'],
            'interest_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'term_weeks' => ['required', 'integer', 'between:1,52'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
