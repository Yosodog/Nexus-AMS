<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSeoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-diagnostic-info') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'indexing_enabled' => ['required', 'boolean'],
            'site_name_override' => ['nullable', 'string', 'max:120'],
            'alliance_name_override' => ['nullable', 'string', 'max:120'],
            'alliance_acronym_override' => ['nullable', 'string', 'max:20'],
            'home_title_override' => ['nullable', 'string', 'max:120'],
            'home_description_override' => ['nullable', 'string', 'max:320'],
            'apply_title_override' => ['nullable', 'string', 'max:120'],
            'apply_description_override' => ['nullable', 'string', 'max:320'],
            'social_image' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => $this->boolean('remove_social_image')),
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:5120',
                Rule::dimensions()
                    ->minWidth(600)
                    ->minHeight(315)
                    ->maxWidth(4096)
                    ->maxHeight(4096),
            ],
            'remove_social_image' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'social_image.prohibited' => 'Choose either a new social image or remove the current image, not both.',
            'social_image.dimensions' => 'The social image must be between 600×315 and 4096×4096 pixels.',
            'social_image.mimes' => 'The social image must be a PNG, JPG, or WebP file.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $textFields = [
            'site_name_override',
            'alliance_name_override',
            'alliance_acronym_override',
            'home_title_override',
            'home_description_override',
            'apply_title_override',
            'apply_description_override',
        ];

        $normalized = [];

        foreach ($textFields as $field) {
            $value = $this->input($field);
            $value = is_string($value) ? trim($value) : null;
            $normalized[$field] = $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }
}
