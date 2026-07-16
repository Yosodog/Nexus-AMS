<?php

namespace App\Http\Requests\Admin;

use App\Services\SettingService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLotterySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-lottery') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lottery_sales_enabled' => ['required', 'boolean'],
            'ticket_price' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) (SettingService::MAX_LOTTERY_TICKET_PRICE_CENTS / 100),
            ],
            'jackpot_percentage' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'max_tickets_per_purchase' => [
                'required',
                'integer',
                'min:1',
                'max:'.SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE,
            ],
            'max_tickets_per_nation' => [
                'required',
                'integer',
                'min:1',
                'max:'.SettingService::MAX_LOTTERY_TICKETS_PER_NATION,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->integer('max_tickets_per_purchase') > $this->integer('max_tickets_per_nation')) {
                $validator->errors()->add(
                    'max_tickets_per_purchase',
                    'The per-purchase limit cannot exceed the per-nation drawing limit.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ticket_price.max' => 'A lottery ticket may cost at most $'.number_format(
                SettingService::MAX_LOTTERY_TICKET_PRICE_CENTS / 100,
            ).'.',
            'jackpot_percentage.decimal' => 'The jackpot share may use at most two decimal places.',
            'max_tickets_per_purchase.max' => 'A single purchase may contain at most 500 tickets.',
            'max_tickets_per_nation.max' => 'A nation may hold at most 10,000 tickets in a drawing.',
        ];
    }
}
