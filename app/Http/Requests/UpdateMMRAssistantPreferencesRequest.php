<?php

namespace App\Http\Requests;

use App\Services\PWHelperService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMMRAssistantPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $nationId = (int) $this->user()?->nation_id;
        $accountRule = Rule::exists('accounts', 'id')
            ->where('nation_id', $nationId)
            ->where('frozen', 0)
            ->whereNull('deleted_at');

        return array_merge([
            'enabled' => ['nullable', 'boolean'],
            'auto_cover_resource_deficits' => ['nullable', 'boolean'],
            'account_id' => ['required', 'integer', $accountRule],
        ], collect(PWHelperService::resources(false))
            ->mapWithKeys(fn (string $resource): array => [
                "{$resource}_pct" => ['nullable', 'numeric', 'min:0', 'max:100'],
            ])
            ->all());
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $percentageFields = collect(PWHelperService::resources(false))
                ->map(fn (string $resource): string => "{$resource}_pct");

            if ($percentageFields->contains(
                fn (string $field): bool => $validator->errors()->has($field)
            )) {
                return;
            }

            $total = $percentageFields->sum(
                fn (string $field): float => (float) $this->input($field, 0)
            );

            if ($total > 100) {
                $validator->errors()->add('allocation_total', 'Total manual allocation cannot exceed 100%.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'Select an active account that belongs to your nation.',
        ];
    }
}
