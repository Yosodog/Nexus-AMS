<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RaidAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['nation_id' => ['required', 'integer', 'min:1'], 'target_id' => ['required', 'integer', 'min:1', 'different:nation_id']];
    }

    public function messages(): array
    {
        return ['nation_id.required' => 'Select your nation.', 'target_id.required' => 'Select a target.', 'target_id.different' => 'Choose a nation other than your own.'];
    }
}
