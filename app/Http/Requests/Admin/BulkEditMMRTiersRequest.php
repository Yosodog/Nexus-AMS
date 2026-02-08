<?php

namespace App\Http\Requests\Admin;

use App\Services\MMRService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkEditMMRTiersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-mmr') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $resourceFields = app(MMRService::class)->getResourceFields();
        $readinessFields = [
            'barracks',
            'factories',
            'hangars',
            'drydocks',
            'missiles',
            'nukes',
            'spies',
        ];
        $allFields = array_merge($resourceFields, $readinessFields);
        $rules = [
            'tier_ids' => ['required', 'array', 'min:1'],
            'tier_ids.*' => ['integer', 'exists:mmr_tiers,id'],
            'resources' => ['required', 'array'],
        ];

        foreach ($allFields as $field) {
            $rules["resources.$field"] = ['nullable', 'numeric', 'min:0'];
        }

        return [
            ...$rules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $resourceFields = app(MMRService::class)->getResourceFields();
            $readinessFields = [
                'barracks',
                'factories',
                'hangars',
                'drydocks',
                'missiles',
                'nukes',
                'spies',
            ];
            $allFields = array_merge($resourceFields, $readinessFields);
            $hasValue = false;

            foreach ($allFields as $field) {
                $value = $this->input("resources.$field");
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                    break;
                }
            }

            if (! $hasValue) {
                $validator->errors()->add('resources', 'Provide at least one resource value to apply.');
            }
        });
    }
}
