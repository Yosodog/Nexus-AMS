<?php

namespace App\Http\Requests\Milcom;

use App\Domain\Milcom\Enums\PriorityTier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CommitScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'generation_version' => ['required', 'integer', 'min:1'],
            'friendly_alliance_ids' => ['present', 'array', 'max:500'],
            'friendly_alliance_ids.*' => ['integer', 'distinct', 'exists:alliances,id'],
            'enemy_alliance_ids' => ['present', 'array', 'max:500'],
            'enemy_alliance_ids.*' => ['integer', 'distinct', 'exists:alliances,id'],
            'included_friendly_nation_ids' => ['sometimes', 'array', 'max:2000'],
            'included_friendly_nation_ids.*' => ['integer', 'distinct', 'exists:nations,id'],
            'excluded_friendly_nation_ids' => ['sometimes', 'array', 'max:2000'],
            'excluded_friendly_nation_ids.*' => ['integer', 'distinct', 'exists:nations,id'],
            'included_target_nation_ids' => ['sometimes', 'array', 'max:2500'],
            'included_target_nation_ids.*' => ['integer', 'distinct', 'exists:nations,id'],
            'excluded_target_nation_ids' => ['sometimes', 'array', 'max:2500'],
            'excluded_target_nation_ids.*' => ['integer', 'distinct', 'exists:nations,id'],
            'priority_overrides' => ['sometimes', 'array', 'max:2500'],
            'priority_overrides.*' => ['string', Rule::enum(PriorityTier::class)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $friendlyIds = array_map('intval', $this->input('friendly_alliance_ids', []));
                $enemyIds = array_map('intval', $this->input('enemy_alliance_ids', []));

                if (array_intersect($friendlyIds, $enemyIds) === []) {
                    return;
                }

                $message = 'An alliance cannot be on both sides. Remove it from one side.';
                $validator->errors()->add('friendly_alliance_ids', $message);
                $validator->errors()->add('enemy_alliance_ids', $message);
            },
        ];
    }
}
