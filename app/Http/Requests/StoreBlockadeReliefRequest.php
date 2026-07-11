<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockadeReliefRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'war_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'deadline_hours' => ['nullable', 'integer', 'between:1,24'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'deadline_hours.between' => 'The relief deadline must be between 1 and 24 hours.',
        ];
    }
}
