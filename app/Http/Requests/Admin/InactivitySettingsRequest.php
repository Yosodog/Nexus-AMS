<?php

namespace App\Http\Requests\Admin;

use App\Enums\InactivityAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InactivitySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-accounts') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inactivity_enabled' => ['required', 'boolean'],
            'inactivity_threshold_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'inactivity_cooldown_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'inactivity_discord_channel_id' => ['nullable', 'string', 'max:255'],
            'inactivity_actions' => ['array'],
            'inactivity_actions.*' => [
                'string',
                Rule::in(array_map(fn (InactivityAction $action) => $action->value, InactivityAction::cases())),
            ],
        ];
    }
}
