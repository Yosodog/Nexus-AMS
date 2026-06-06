<?php

namespace App\Http\Requests\Admin;

use App\Models\MMRSetting;
use App\Services\PWHelperService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMMRAssistantSettingsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'resources' => ['required', 'array'],
            'resources.*' => ['array:enabled,surcharge_pct'],
            'resources.*.enabled' => ['nullable', 'boolean'],
            'resources.*.surcharge_pct' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:'.MMRSetting::MIN_SURCHARGE_PCT,
                'max:'.MMRSetting::MAX_SURCHARGE_PCT,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $resources = $this->input('resources', []);

            if (! is_array($resources)) {
                return;
            }

            $allowedResources = PWHelperService::resources(false);

            foreach (array_keys($resources) as $resource) {
                if (! in_array($resource, $allowedResources, true)) {
                    $validator->errors()->add("resources.{$resource}", 'That resource cannot be configured for MMR Assistant.');
                }
            }
        });
    }
}
