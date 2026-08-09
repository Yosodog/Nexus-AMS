<?php

namespace App\Http\Requests\Discord;

class DiscordOperationsWorkItemReleaseRequest extends DiscordOperationsWorkItemClaimRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
