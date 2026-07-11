<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class DiscordFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function prohibitedAuthorityRules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'nation_id' => ['prohibited'],
            'discord_user_id' => ['prohibited'],
            'guild_id' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The request payload is invalid.',
                'details' => $validator->errors()->toArray(),
            ],
            'meta' => ['contract_version' => 1],
        ], 422));
    }
}
