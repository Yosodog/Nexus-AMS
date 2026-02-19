<?php

namespace App\Http\Requests\Admin\Customization;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'metadata' => ['sometimes', 'array'],
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
}
