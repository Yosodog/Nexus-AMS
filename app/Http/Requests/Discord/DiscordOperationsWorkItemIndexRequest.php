<?php

namespace App\Http\Requests\Discord;

use App\Services\StaffWorkQueue\StaffWorkQueueFilterSet;

class DiscordOperationsWorkItemIndexRequest extends DiscordOperationsWorkItemRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $sourceTypes = array_keys((array) config('operations.sources', []));

        return [
            ...StaffWorkQueueFilterSet::rules($sourceTypes),
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config('operations.pagination.maximum', 100),
            ],
            ...$this->prohibitedAuthorityRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'The requested Operations source is not supported.',
            'page.min' => 'The page must be at least 1.',
            'per_page.max' => 'The page size exceeds the Operations provider limit.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = collect([
            'q', 'type', 'urgency', 'owner', 'domain_owner', 'team', 'priority', 'severity',
            'attention_reason', 'assignee', 'requester', 'next_actor', 'due_from', 'due_to',
            'changed_from', 'changed_to', 'freshness', 'sort', 'direction',
        ])->mapWithKeys(function (string $key): array {
            $value = $this->input($key);

            return [$key => is_string($value) ? trim($value) : $value];
        })->all();

        $this->merge($trimmed);
    }
}
