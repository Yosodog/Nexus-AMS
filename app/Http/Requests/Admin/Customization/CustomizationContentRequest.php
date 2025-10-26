<?php

namespace App\Http\Requests\Admin\Customization;

use Illuminate\Foundation\Http\FormRequest;

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

    public function metadata(): array
    {
        return $this->input('metadata', []);
    }
}
