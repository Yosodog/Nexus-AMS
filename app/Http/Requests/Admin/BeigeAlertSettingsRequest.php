<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BeigeAlertSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-raids') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'beige_alerts_enabled' => ['required', 'boolean'],
            'beige_alerts_discord_channel_id' => ['nullable', 'string', 'max:190'],
        ];
    }
}
