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

    public function rules(): array
    {
        return [
            'blocks' => ['required', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function blocks(): array
    {
        return $this->input('blocks', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->input('metadata', []);
    }
}
