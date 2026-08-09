<?php

namespace App\Http\Requests\Discord;

class DiscordOperationsWorkItemShowRequest extends DiscordOperationsWorkItemRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'work_item_type' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'work_item_id' => ['required', 'string', 'max:191', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/i'],
            ...$this->prohibitedAuthorityRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'work_item_type.regex' => 'The Operations source type is invalid.',
            'work_item_id.regex' => 'The Operations work-item identifier is invalid.',
        ];
    }

    public function workKey(): string
    {
        return $this->validated('work_item_type').':'.$this->validated('work_item_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'work_item_type' => trim((string) $this->route('type')),
            'work_item_id' => trim((string) $this->route('id')),
        ]);
    }
}
