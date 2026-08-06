<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $nationId = (int) ($this->user()?->nation_id ?? 0);

        return [
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('nation_id', $nationId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required' => 'Select an account for the city grant disbursement.',
            'account_id.exists' => 'The selected account is unavailable or does not belong to your nation.',
        ];
    }
}
