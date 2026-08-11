<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateMemberBuildRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-audits') === true;
    }

    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
