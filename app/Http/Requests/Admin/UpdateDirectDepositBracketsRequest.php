<?php

namespace App\Http\Requests\Admin;

use App\Models\DirectDepositTaxBracket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDirectDepositBracketsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-dd') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['integer', 'distinct', 'exists:direct_deposit_tax_brackets,id'],
            'rates' => ['required', 'array:'.implode(',', DirectDepositTaxBracket::rateFields())],
        ];

        foreach (DirectDepositTaxBracket::rateFields() as $resource) {
            $rules["rates.{$resource}"] = [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:'.DirectDepositTaxBracket::MIN_TAX_RATE,
                'max:'.DirectDepositTaxBracket::MAX_TAX_RATE,
            ];
        }

        return [
            ...$rules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rates = $this->input('rates', []);

            if (! is_array($rates)) {
                return;
            }

            $hasRate = collect(DirectDepositTaxBracket::rateFields())
                ->contains(fn (string $resource): bool => ($rates[$resource] ?? null) !== null && $rates[$resource] !== '');

            if (! $hasRate) {
                $validator->errors()->add('rates', 'Provide at least one direct deposit rate to update.');
            }
        });
    }
}
