<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttachMilcomObjectiveRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'objective_id' => ['required', 'integer', 'exists:milcom_objectives,id'],
            'dispatch_id' => ['required', 'integer', 'exists:milcom_dispatches,id'],
            'discord_channel_id' => ['required', 'regex:/^\d{17,20}$/'],
        ];
    }
}
