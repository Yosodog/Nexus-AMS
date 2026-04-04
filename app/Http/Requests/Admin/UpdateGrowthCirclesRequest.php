<?php

namespace App\Http\Requests\Admin;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGrowthCirclesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-growth-circles') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'growth_circles_enabled' => ['required', 'boolean'],
            'growth_circle_tax_id' => ['required', 'integer', 'min:0'],
            'growth_circle_fallback_tax_id' => ['required', 'integer', 'min:0'],
            'growth_circle_source_account_id' => ['required', 'integer', 'min:0'],
            'growth_circle_food_per_city' => ['required', 'integer', 'min:0'],
            'growth_circle_uranium_per_city' => ['required', 'integer', 'min:0'],
            'growth_circle_discord_channel_id' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('growth_circles_enabled')) {
                    return;
                }

                $requiredPositiveFields = [
                    'growth_circle_tax_id' => 'A Growth Circles tax bracket is required when the program is enabled.',
                    'growth_circle_fallback_tax_id' => 'A fallback tax bracket is required when the program is enabled.',
                    'growth_circle_source_account_id' => 'A source account is required when the program is enabled.',
                    'growth_circle_food_per_city' => 'Food per city must be greater than zero when the program is enabled.',
                    'growth_circle_uranium_per_city' => 'Uranium per city must be greater than zero when the program is enabled.',
                ];

                foreach ($requiredPositiveFields as $field => $message) {
                    if ((int) $this->input($field, 0) <= 0) {
                        $validator->errors()->add($field, $message);
                    }
                }

                $sourceAccountId = (int) $this->input('growth_circle_source_account_id', 0);

                if ($sourceAccountId > 0 && ! Account::query()->whereNull('nation_id')->whereKey($sourceAccountId)->exists()) {
                    $validator->errors()->add(
                        'growth_circle_source_account_id',
                        'The Growth Circles source account must be an alliance-owned account.'
                    );
                }
            },
        ];
    }
}
