<?php

namespace App\Http\Requests\Discord;

class DiscordResourceShortfallDraftRequest extends DiscordFinanceRequest
{
    public function rules(): array
    {
        return $this->prohibitedAuthorityRules() + [
            'account_id' => ['required', 'integer', 'min:1'],
            'resources' => ['prohibited'],
            'purchase_lines' => ['prohibited'],
            'quoted_total' => ['prohibited'],
        ];
    }
}
