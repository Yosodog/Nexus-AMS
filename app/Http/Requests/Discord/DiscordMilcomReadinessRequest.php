<?php

namespace App\Http\Requests\Discord;

final class DiscordMilcomReadinessRequest extends DiscordMilcomProjectionRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedAuthorityRules(),
            'nation_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function nationId(): ?int
    {
        $nationId = $this->validated('nation_id');

        return is_numeric($nationId) ? (int) $nationId : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nation_id.integer' => 'The nation ID must be an integer.',
            'nation_id.min' => 'The nation ID must be positive.',
        ];
    }
}
