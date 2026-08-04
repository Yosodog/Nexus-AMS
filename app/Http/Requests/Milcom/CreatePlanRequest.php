<?php

namespace App\Http\Requests\Milcom;

use App\Enums\WarTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'deadline_at' => ['nullable', 'date', 'after:now'],
            'default_war_type' => ['nullable', Rule::in(WarTypeEnum::values())],
            'default_war_reason' => ['nullable', 'string', 'max:255'],
            'discord_forum_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'wave' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'default_war_type' => $this->filled('default_war_type')
                ? strtoupper((string) $this->input('default_war_type'))
                : null,
            'default_war_reason' => $this->filled('default_war_reason')
                ? trim((string) $this->input('default_war_reason'))
                : null,
            'discord_forum_id' => $this->filled('discord_forum_id')
                ? trim((string) $this->input('discord_forum_id'))
                : null,
        ]);
    }
}
