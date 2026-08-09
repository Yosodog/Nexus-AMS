<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscordLinkConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'intent_id' => ['required', 'string', 'size:64', 'alpha_num'],
        ];
    }
}
