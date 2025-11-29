<?php

namespace App\Http\Requests\Discord;

use Illuminate\Foundation\Http\FormRequest;

class DiscordAttachChannelRequest extends FormRequest
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
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'discord_channel_id' => ['required', 'string', 'max:100'],
        ];
    }
}
