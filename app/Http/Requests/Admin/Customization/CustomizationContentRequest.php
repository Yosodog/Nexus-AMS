<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation rules for editor submissions targeting custom pages.
 */
abstract class CustomizationContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-custom-pages') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'metadata' => ['sometimes', 'array'],
            'page_metadata' => ['sometimes', 'nullable', 'array'],
            'page_metadata.title' => ['required_with:page_metadata', 'string', 'max:255'],
            'page_metadata.description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'page_metadata.audience' => ['required_with:page_metadata', 'string', 'in:public,member'],
        ];
    }

    public function content(): string
    {
        return (string) $this->input('content', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->input('metadata', []);
    }

    /**
     * @return array{title: string, description: string|null, audience: string}|null
     */
    public function pageMetadata(): ?array
    {
        $metadata = $this->input('page_metadata');

        if (! is_array($metadata)) {
            return null;
        }

        return [
            'title' => trim((string) ($metadata['title'] ?? '')),
            'description' => (($description = trim((string) ($metadata['description'] ?? ''))) !== '')
                ? $description
                : null,
            'audience' => (string) ($metadata['audience'] ?? 'public'),
        ];
    }
}
