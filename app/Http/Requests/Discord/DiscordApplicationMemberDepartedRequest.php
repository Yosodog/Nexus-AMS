<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscordApplicationMemberDepartedRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['discord_user_id' => ['required', 'string', 'regex:/^\d{17,20}$/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['discord_user_id.regex' => 'A valid Discord user ID is required.'];
    }
}
