<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscordApplicationDenyRequest extends FormRequest
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
        return [
            'applicant_discord_id' => ['required', 'string', 'max:100'],
            'moderator_discord_id' => ['required', 'string', 'max:100'],
            'denial_request_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
