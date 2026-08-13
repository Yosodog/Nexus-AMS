<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRaidFinderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-raids') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'top_cap' => ['required', 'integer', 'min:1', 'max:1000'],
            'raid_activity_city_threshold' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:'.SettingService::MAX_RAID_ACTIVITY_CITY_THRESHOLD,
            ],
            'raid_minimum_inactive_turns' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                'max:'.SettingService::MAX_RAID_MINIMUM_INACTIVE_TURNS,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'raid_activity_city_threshold.max' => 'The activity city threshold may not exceed 1,000 cities.',
            'raid_minimum_inactive_turns.max' => 'The inactivity requirement may not exceed 4,380 turns.',
        ];
    }
}
