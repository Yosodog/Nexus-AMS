<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGrowthCirclesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-growth-circles') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'growth_circles_enabled' => ['required', 'boolean'],
            'growth_circle_tax_id' => ['required', 'integer', 'min:0'],
            'growth_circle_fallback_tax_id' => ['required', 'integer', 'min:0'],
            'growth_circle_source_account_id' => ['required', 'integer', 'min:0'],
            'growth_circle_food_per_city' => ['required', 'integer', 'min:0'],
            'growth_circle_uranium_per_city' => ['required', 'integer', 'min:0'],
            'growth_circle_discord_channel_id' => ['nullable', 'string', 'max:30'],
        ];
    }
}
