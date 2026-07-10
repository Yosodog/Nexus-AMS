<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendManualGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'grant_id' => ['required', 'integer', 'exists:grants,id'],
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
