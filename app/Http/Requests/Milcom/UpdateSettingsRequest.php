<?php

namespace App\Http\Requests\Milcom;

use App\Enums\WarTypeEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'forum_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'defense_role_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'war_alert_channel_id' => ['nullable', 'regex:/^\d{17,20}$/'],
            'war_alert_enabled' => ['required', 'boolean'],
            'forum_tag_ids' => ['sometimes', 'array', 'max:5'],
            'forum_tag_ids.*' => ['regex:/^\d{17,20}$/', 'distinct'],
            'counter_monitoring_enabled' => ['required', 'boolean'],
            'default_war_type' => ['required', Rule::in(WarTypeEnum::values())],
            'default_war_reason' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $tags = $this->input('forum_tag_ids', []);

        if (is_string($tags)) {
            $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        $this->merge([
            'forum_id' => $this->filled('forum_id') ? trim((string) $this->input('forum_id')) : null,
            'defense_role_id' => $this->filled('defense_role_id')
                ? trim((string) $this->input('defense_role_id'))
                : null,
            'war_alert_channel_id' => $this->filled('war_alert_channel_id')
                ? trim((string) $this->input('war_alert_channel_id'))
                : null,
            'war_alert_enabled' => $this->boolean('war_alert_enabled'),
            'forum_tag_ids' => $tags,
            'counter_monitoring_enabled' => $this->boolean('counter_monitoring_enabled'),
            'default_war_type' => strtoupper((string) $this->input('default_war_type', 'ORDINARY')),
            'default_war_reason' => trim((string) $this->input('default_war_reason')),
        ]);
    }
}
