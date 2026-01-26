<?php

namespace App\Http\Requests\Admin;

use App\Services\PWHelperService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-city-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'city_number' => ['required', 'integer', 'min:1', 'unique:city_grants,city_number'],
            'grant_amount' => ['required', 'integer', 'min:1', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'projects' => ['nullable', 'array'],
            'projects.*' => ['string', Rule::in(array_keys(PWHelperService::PROJECTS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city_number.required' => 'City number is required.',
            'city_number.unique' => 'A city grant already exists for that city number.',
            'grant_amount.max' => 'City grant percentage must be 1000% or less.',
        ];
    }
}
