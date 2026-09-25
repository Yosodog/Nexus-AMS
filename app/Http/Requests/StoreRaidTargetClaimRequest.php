<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRaidTargetClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'target_nation_id' => ['required', 'integer', 'exists:raid_target_profiles,nation_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_nation_id.required' => 'Select a target to claim.',
            'target_nation_id.integer' => 'Select a valid target.',
            'target_nation_id.exists' => 'That target is not in the raid finder.',
        ];
    }
}
