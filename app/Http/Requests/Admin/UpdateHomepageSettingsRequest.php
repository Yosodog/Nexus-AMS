<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHomepageSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'home_headline' => ['required', 'string', 'max:160'],
            'home_tagline' => ['required', 'string', 'max:240'],
            'home_about' => ['nullable', 'string', 'max:800'],
            'home_stats_intro' => ['nullable', 'string', 'max:240'],
            'home_closing_text' => ['nullable', 'string', 'max:300'],
            'home_hero_badge' => ['nullable', 'string', 'max:60'],
            'home_cta_label' => ['nullable', 'string', 'max:60'],
            'home_highlights' => ['array'],
            'home_highlights.*' => ['nullable', 'string', 'max:140'],
        ];
    }
}
