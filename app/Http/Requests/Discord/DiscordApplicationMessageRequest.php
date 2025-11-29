<?php

namespace App\Http\Requests\Discord;

use Illuminate\Foundation\Http\FormRequest;

class DiscordApplicationMessageRequest extends FormRequest
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
            'discord_channel_id' => ['required', 'string', 'max:100'],
            'discord_message_id' => ['required', 'string', 'max:100'],
            'discord_user_id' => ['required', 'string', 'max:100'],
            'discord_username' => ['required', 'string', 'max:191'],
            'content' => ['required', 'string'],
            'sent_at' => ['required', 'integer'],
            'is_staff' => ['required', 'boolean'],
        ];
    }
}
