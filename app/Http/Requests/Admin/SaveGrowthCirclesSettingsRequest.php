<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveGrowthCirclesSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-growth-circles') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'growth_circles_tax_id' => ['required', 'integer', 'min:1'],
            'growth_circles_fallback_tax_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
