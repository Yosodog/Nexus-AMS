<?php

namespace App\Http\Requests\Discord;

use Illuminate\Contracts\Validation\ValidationRule;

final class DiscordMilcomAssignmentResponseConfirmRequest extends DiscordMilcomProjectionRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'assignment' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'response' => ['prohibited'],
            'reason' => ['prohibited'],
            'intent_id' => ['required', 'string', 'size:64', 'alpha_num'],
        ];
    }

    public function intentId(): string
    {
        return $this->string('intent_id')->toString();
    }
}
