<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendManualCityGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-city-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'city_grant_id' => ['required', 'integer', 'exists:city_grants,id'],
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'city_number' => ['nullable', 'integer', 'min:1'],
            'grant_amount' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
