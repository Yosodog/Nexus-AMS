<?php

namespace App\Http\Requests\Discord;

use App\Services\PWHelperService;

class DiscordWithdrawalDraftRequest extends DiscordFinanceRequest
{
    public function rules(): array
    {
        $rules = $this->prohibitedAuthorityRules() + [
            'account_id' => ['required', 'integer', 'min:1'],
            'resources' => ['required', 'array'],
        ];

        foreach (PWHelperService::resources() as $resource) {
            $rules["resources.{$resource}"] = [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
                'max:9999999999999.99',
            ];
        }

        return $rules;
    }
}
