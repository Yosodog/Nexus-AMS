<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendCityGrantReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-city-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'grant_ids' => ['required', 'array', 'min:1'],
            'grant_ids.*' => ['integer', 'exists:city_grants,id'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grant_ids.required' => 'Select at least one city grant.',
            'grant_ids.array' => 'Select at least one city grant.',
            'grant_ids.min' => 'Select at least one city grant.',
            'grant_ids.*.exists' => 'One or more selected city grants could not be found.',
            'message.required' => 'Provide the reminder message to send.',
            'message.max' => 'Reminder message must be 2000 characters or less.',
        ];
    }
}
