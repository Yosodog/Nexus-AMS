<?php

namespace App\Http\Requests\Discord;

use Illuminate\Foundation\Http\FormRequest;

class DiscordDirectorySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:64', 'not_regex:/[\x00-\x1F\x7F]/u'],
        ];
    }
}
