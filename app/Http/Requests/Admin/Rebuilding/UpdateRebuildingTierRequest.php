<?php

namespace App\Http\Requests\Admin\Rebuilding;

use App\Models\RebuildingTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRebuildingTierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-rebuilding') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'min_city_count' => ['required', 'integer', 'min:1'],
            'max_city_count' => ['nullable', 'integer', 'gte:min_city_count'],
            'target_infrastructure' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['string', 'in:urban_planning,advanced_urban_planning,center_for_civil_engineering,advanced_engineering_corps,government_support_agency'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('is_active', true)) {
                return;
            }

            $tier = $this->route('tier');
            $tierId = $tier instanceof RebuildingTier ? $tier->id : (int) $tier;
            $min = (int) $this->input('min_city_count');
            $maxInput = $this->input('max_city_count');
            $max = $maxInput === null || $maxInput === '' ? null : (int) $maxInput;

            $overlap = RebuildingTier::query()
                ->where('id', '!=', $tierId)
                ->where('is_active', true)
                ->where(function ($query) use ($min) {
                    $query->whereNull('max_city_count')
                        ->orWhere('max_city_count', '>=', $min);
                })
                ->where(function ($query) use ($max) {
                    if ($max === null) {
                        return $query;
                    }

                    return $query->where('min_city_count', '<=', $max);
                })
                ->exists();

            if ($overlap) {
                $validator->errors()->add('min_city_count', 'City range overlaps an existing active rebuilding tier.');
            }
        });
    }
}
