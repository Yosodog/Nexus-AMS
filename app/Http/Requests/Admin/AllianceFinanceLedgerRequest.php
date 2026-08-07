<?php

namespace App\Http\Requests\Admin;

use App\Services\Finance\AllianceFinanceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class AllianceFinanceLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-financial-reports') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', Rule::date()->format('Y-m-d')],
            'to' => ['nullable', Rule::date()->format('Y-m-d')],
            'direction' => ['nullable', Rule::in(['both', 'income', 'expense'])],
            'categories' => ['nullable', 'array', 'max:25'],
            'categories.*' => ['string', 'max:50'],
            'search' => ['nullable', 'string', 'max:120'],
            'resource' => ['nullable', Rule::in(AllianceFinanceService::FILTERABLE_RESOURCES)],
            'sort' => ['nullable', Rule::in(['date', 'amount'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'The start date must use the YYYY-MM-DD format.',
            'to.date_format' => 'The end date must use the YYYY-MM-DD format.',
            'categories.max' => 'Select no more than 25 finance categories.',
            'search.max' => 'Search text may not exceed 120 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalize = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);

            return $value === '' ? null : $value;
        };

        $categories = collect(Arr::wrap($this->input('categories', [])))
            ->filter(static fn (mixed $category): bool => is_string($category))
            ->map(static fn (string $category): string => trim($category))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $resource = $normalize($this->input('resource'));

        $this->merge([
            'from' => $normalize($this->input('from')),
            'to' => $normalize($this->input('to')),
            'direction' => strtolower($normalize($this->input('direction')) ?? 'both'),
            'categories' => $categories,
            'search' => $normalize($this->input('search')),
            'resource' => $resource === null ? null : strtolower($resource),
            'sort' => strtolower($normalize($this->input('sort')) ?? 'date'),
            'sort_direction' => strtolower($normalize($this->input('sort_direction')) ?? 'desc'),
        ]);
    }
}
