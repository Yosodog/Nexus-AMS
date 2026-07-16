<?php

namespace App\Http\Requests;

use App\Services\AllianceMemberEligibilityService;
use App\Services\SettingService;
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
        $user = $this->user();

        return $user !== null
            && $user->nation !== null
            && app(AllianceMemberEligibilityService::class)->isEligibleNation($user->nation);
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
            'drawing_id' => ['required', 'integer', Rule::exists('lottery_drawings', 'id')],
            'account_id' => ['required', 'integer', $accountRule],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'drawing_id.exists' => 'This lottery drawing is no longer available. Refresh the page and try again.',
            'account_id.exists' => 'Select one of your active accounts.',
            'quantity.min' => 'Purchase at least one ticket.',
            'quantity.max' => 'You may purchase at most '.SettingService::MAX_LOTTERY_TICKETS_PER_PURCHASE.' tickets at a time.',
        ];
    }
}
