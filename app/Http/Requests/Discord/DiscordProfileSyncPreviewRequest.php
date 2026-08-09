<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscordProfileSyncPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'observed' => ['required', 'array:nickname,role_ids'],
            'observed.nickname' => ['nullable', 'string', 'max:32', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'observed.role_ids' => ['present', 'array', 'max:100'],
            'observed.role_ids.*' => ['required', 'string', 'regex:/^\d{17,20}$/', 'distinct:strict'],
        ];
    }
}
