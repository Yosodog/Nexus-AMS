<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiscordCityTierSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-diagnostic-info') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'discord_city_tier_bucket_size' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'discord_city_tier_bucket_size.min' => 'City tiers must contain at least one city.',
            'discord_city_tier_bucket_size.max' => 'City tiers may contain at most 100 cities.',
        ];
    }
}
