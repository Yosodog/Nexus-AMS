<?php

namespace App\Http\Requests\Admin;

use App\Models\MarketResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketResourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-market') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adjustment_percent' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:'.MarketResource::MIN_ADJUSTMENT_PERCENT,
                'max:'.MarketResource::MAX_ADJUSTMENT_PERCENT,
            ],
            'buy_cap_remaining' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:'.MarketResource::MAX_BUY_CAP_REMAINING,
            ],
        ];
    }
}
