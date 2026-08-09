<?php

namespace App\Http\Requests\Discord;

use App\Services\Discord\DiscordMilcomAssignmentResponseService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class DiscordMilcomAssignmentResponsePreviewRequest extends DiscordMilcomProjectionRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'assignment' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'response' => ['required', 'string', Rule::in(DiscordMilcomAssignmentResponseService::RESPONSES)],
            'reason' => [
                'nullable',
                'string',
                'max:500',
                'not_regex:/[\x00-\x1F\x7F]/u',
                'required_if:response,unavailable',
                'prohibited_unless:response,unavailable',
            ],
        ];
    }

    public function responseValue(): string
    {
        return (string) $this->validated('response');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
