<?php

namespace App\Http\Requests\Admin;

use App\Models\Grants;
use App\Rules\ValidGrantRequirementTree;
use App\Services\GrantRequirementService;
use App\Services\PWHelperService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JsonException;

class UpdateGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $grant = $this->route('grant');
        $grantId = $grant instanceof Grants ? $grant->id : $grant;

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('grants', 'name')->ignore($grantId),
            ],
            'description' => ['required', 'string'],
            'money' => ['nullable', 'integer', 'min:0'],
            'is_enabled' => ['nullable', 'in:true,false,1,0,on,off'],
            'is_one_time' => ['nullable', 'in:true,false,1,0,on,off'],
            'validation_rules' => ['nullable', new ValidGrantRequirementTree(app(GrantRequirementService::class))],
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $rules[$resource] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'name.required' => 'A grant name is required.',
            'name.unique' => 'A grant with this name already exists.',
            'description.required' => 'A grant description is required.',
            'money.integer' => 'Money must be a whole number.',
            'money.min' => 'Money must be 0 or greater.',
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $messages["{$resource}.integer"] = 'Grant resources must be whole numbers.';
            $messages["{$resource}.min"] = 'Grant resources must be 0 or greater.';
        }

        return $messages;
    }

    protected function prepareForValidation(): void
    {
        $rawValidationRules = $this->input('validation_rules_json');

        if ($rawValidationRules === null || trim((string) $rawValidationRules) === '') {
            $this->merge(['validation_rules' => null]);

            return;
        }

        try {
            $decodedValidationRules = json_decode((string) $rawValidationRules, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->merge(['validation_rules' => '__invalid_json__']);

            return;
        }

        $this->merge(['validation_rules' => $decodedValidationRules]);
    }
}
