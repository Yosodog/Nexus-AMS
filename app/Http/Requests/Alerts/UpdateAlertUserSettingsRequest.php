<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAlertUserSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'quiet_hours_start' => ['nullable', 'required_with:quiet_hours_end', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'required_with:quiet_hours_start', 'date_format:H:i'],
            'default_digest_time' => ['required', 'date_format:H:i'],
            'default_digest_weekday' => ['required', 'integer', 'between:1,7'],
            'discord_enabled' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $start = $this->input('quiet_hours_start');
            $end = $this->input('quiet_hours_end');

            if (is_string($start) && $start !== '' && $start === $end) {
                $validator->errors()->add(
                    'quiet_hours_end',
                    'Quiet hours must have different start and end times.',
                );
            }
        }];
    }
}
