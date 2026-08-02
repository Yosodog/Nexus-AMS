<?php

namespace App\Http\Requests\Admin;

use App\Models\CityGrant;
use App\Rules\ValidGrantRequirementTree;
use App\Services\GrantRequirementService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JsonException;

class UpdateCityGrantRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $grant = $this->route('city_grant');
        $grantId = $grant instanceof CityGrant ? $grant->id : $grant;

        return [
            'city_number' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('city_grants', 'city_number')->ignore($grantId),
            ],
            'grant_amount' => ['required', 'integer', 'min:1', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'requirements' => ['nullable', new ValidGrantRequirementTree(app(GrantRequirementService::class))],
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

    protected function prepareForValidation(): void
    {
        $rawRequirements = $this->input('requirements_json');

        if ($rawRequirements === null || trim((string) $rawRequirements) === '') {
            $this->merge(['requirements' => null]);

            return;
        }

        try {
            $decodedRequirements = json_decode((string) $rawRequirements, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->merge(['requirements' => '__invalid_json__']);

            return;
        }

        $this->merge(['requirements' => $decodedRequirements]);
    }
}
