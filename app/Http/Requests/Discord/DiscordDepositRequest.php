<?php

namespace App\Http\Requests\Discord;

class DiscordDepositRequest extends DiscordFinanceRequest
{
    public function rules(): array
    {
        return $this->prohibitedAuthorityRules();
    }
}
