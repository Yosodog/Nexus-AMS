<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegenerateNationBuildRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->nation !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'authorization' => 'Link a nation before regenerating a build recommendation.',
        ];
    }
}
