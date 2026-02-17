<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBeigeAlertAllianceRequest extends FormRequest
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
            'alliance_id' => ['required', 'integer', 'exists:alliances,id', 'unique:beige_alert_alliances,alliance_id'],
        ];
    }
}
