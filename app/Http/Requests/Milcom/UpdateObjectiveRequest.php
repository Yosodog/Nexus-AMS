<?php

namespace App\Http\Requests\Milcom;

use App\Domain\Milcom\Enums\PriorityTier;
use App\Enums\WarTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'generation_version' => ['required', 'integer', 'min:1'],
            'priority_tier' => ['sometimes', Rule::enum(PriorityTier::class)],
            'priority_score' => ['sometimes', 'numeric', 'min:0', 'max:99999.99'],
            'desired_team_depth' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'minimum_team_depth' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'war_type' => ['sometimes', Rule::in(WarTypeEnum::values())],
            'war_reason' => ['nullable', 'string', 'max:255'],
            'deadline_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('war_type')) {
            $this->merge(['war_type' => strtoupper((string) $this->input('war_type'))]);
        }
    }
}
