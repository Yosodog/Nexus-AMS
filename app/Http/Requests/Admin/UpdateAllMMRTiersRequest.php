<?php

namespace App\Http\Requests\Admin;

use App\Models\MMRTier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAllMMRTiersRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    public const FIELDS = [
        'money',
        'steel',
        'aluminum',
        'munitions',
        'uranium',
        'food',
        'gasoline',
        'barracks',
        'factories',
        'hangars',
        'drydocks',
        'missiles',
        'nukes',
        'spies',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-mmr') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*' => ['required', 'array:'.implode(',', self::FIELDS)],
        ];

        foreach (self::FIELDS as $field) {
            $rules["tiers.*.{$field}"] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tiers = $this->input('tiers', []);

            if (! is_array($tiers)) {
                return;
            }

            $submittedTierIds = [];

            foreach (array_keys($tiers) as $tierId) {
                $validatedTierId = filter_var($tierId, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($validatedTierId === false) {
                    $validator->errors()->add("tiers.{$tierId}", 'MMR tier IDs must be positive integers.');

                    continue;
                }

                $submittedTierIds[] = $validatedTierId;
            }

            $existingTierIds = MMRTier::query()
                ->whereKey($submittedTierIds)
                ->pluck('id')
                ->map(fn (int $id): int => $id)
                ->all();

            foreach ($submittedTierIds as $tierId) {
                if (! in_array($tierId, $existingTierIds, true)) {
                    $validator->errors()->add("tiers.{$tierId}", 'The selected MMR tier does not exist.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tiers.required' => 'At least one MMR tier is required.',
            'tiers.array' => 'The MMR tiers payload must be an array.',
            'tiers.min' => 'At least one MMR tier is required.',
            'tiers.*.array' => 'Each MMR tier must contain a valid record.',
            'tiers.*.*.integer' => 'MMR tier values must be whole numbers.',
            'tiers.*.*.min' => 'MMR tier values cannot be negative.',
        ];
    }
}
