<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate creation details for a new custom page.
 */
class CustomizationPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-custom-pages') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => strtolower(trim((string) $this->input('slug'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pages', 'slug'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === 'apply') {
                        $fail('The apply slug is reserved for the application page.');
                    }
                },
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'audience' => ['required', 'string', 'in:public,member'],
        ];
    }

    public function slug(): string
    {
        return (string) $this->validated('slug');
    }

    /**
     * @return array{title: string, description: string|null, audience: string}
     */
    public function pageMetadata(): array
    {
        $description = trim((string) ($this->validated('description') ?? ''));

        return [
            'title' => trim((string) $this->validated('title')),
            'description' => $description !== '' ? $description : null,
            'audience' => (string) $this->validated('audience'),
        ];
    }
}
