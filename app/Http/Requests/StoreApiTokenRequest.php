<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiTokenRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Provide a name for the token.',
            'name.max' => 'Token names may not be longer than 255 characters.',
            'expires_at.date' => 'Expiration must be a valid date.',
            'expires_at.after_or_equal' => 'Expiration must be today or later.',
            'current_password.required' => 'Enter your current password to manage API tokens.',
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
