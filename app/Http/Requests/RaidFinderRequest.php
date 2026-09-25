<?php

namespace App\Http\Requests;

use App\DataTransferObjects\Raids\RaidFinderFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RaidFinderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_expected_net' => ['nullable', 'numeric'],
            'min_inactive_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'beige_within_turns' => ['nullable', 'integer', 'min:0', 'max:24'],
            'alliance_scope' => ['nullable', Rule::in([
                RaidFinderFilters::SCOPE_ANY,
                RaidFinderFilters::SCOPE_UNALIGNED,
                RaidFinderFilters::SCOPE_ALIGNED,
            ])],
            'beatable_only' => ['nullable', 'boolean'],
            'hide_claimed' => ['nullable', 'boolean'],
            'fresh' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.min' => 'Show at least one target.',
            'limit.max' => 'Show at most 100 targets.',
            'min_expected_net.numeric' => 'Minimum expected profit must be a number.',
            'min_inactive_days.max' => 'Inactivity can be at most 365 days.',
            'min_inactive_days.integer' => 'Inactivity must be a whole number of days.',
            'beige_within_turns.max' => 'Beige can end within at most 24 turns.',
            'beige_within_turns.integer' => 'Beige turns must be a whole number.',
            'alliance_scope.in' => 'Choose any, unaligned, or aligned targets.',
        ];
    }

    public function filters(): RaidFinderFilters
    {
        return new RaidFinderFilters(
            limit: (int) ($this->validated('limit') ?? 50),
            minExpectedNet: $this->validated('min_expected_net') === null ? null : (float) $this->validated('min_expected_net'),
            minInactiveDays: $this->validated('min_inactive_days') === null ? null : (int) $this->validated('min_inactive_days'),
            beigeWithinTurns: (int) ($this->validated('beige_within_turns') ?? 0),
            allianceScope: (string) ($this->validated('alliance_scope') ?? RaidFinderFilters::SCOPE_ANY),
            beatableOnly: $this->boolean('beatable_only'),
            hideClaimed: $this->boolean('hide_claimed'),
            fresh: $this->boolean('fresh'),
        );
    }
}
