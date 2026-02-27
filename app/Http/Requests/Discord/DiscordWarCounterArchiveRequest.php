<?php

namespace App\Http\Requests\Discord;

use Illuminate\Foundation\Http\FormRequest;

class DiscordWarCounterArchiveRequest extends FormRequest
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
            'war_counter_id' => ['required', 'integer', 'exists:war_counters,id'],
        ];
    }
}
