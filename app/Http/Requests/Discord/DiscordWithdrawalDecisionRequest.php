<?php

namespace App\Http\Requests\Discord;

class DiscordWithdrawalDecisionRequest extends DiscordFinanceRequest
{
    public function rules(): array
    {
        return $this->prohibitedAuthorityRules();
    }
}
