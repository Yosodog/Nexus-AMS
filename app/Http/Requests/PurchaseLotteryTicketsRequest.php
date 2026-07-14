<?php

namespace App\Http\Requests;

use App\Services\LotteryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseLotteryTicketsRequest extends FormRequest
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
        $accountRule = Rule::exists('accounts', 'id')->whereNull('deleted_at');

        if ($this->user()) {
            $accountRule = $accountRule->where('nation_id', $this->user()->nation_id);
        }

        return [
            'account_id' => ['required', 'integer', $accountRule],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.LotteryService::MAX_TICKETS_PER_PURCHASE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'Select one of your active accounts.',
            'quantity.min' => 'Purchase at least one ticket.',
            'quantity.max' => 'You may purchase at most '.LotteryService::MAX_TICKETS_PER_PURCHASE.' tickets at a time.',
        ];
    }
}
