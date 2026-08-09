<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscordApplicationPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'nation_id' => ['required', 'integer', 'min:1'],
            'discord_username' => ['required', 'string', 'max:191', 'not_regex:/[\x00-\x1F\x7F]/u'],
        ];
    }
}
