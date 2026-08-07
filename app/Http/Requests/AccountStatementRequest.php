<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Services\Finance\MemberAccountStatementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountStatementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => filled($this->input('from'))
                ? $this->input('from')
                : now()->subDays(90)->toDateString(),
            'to' => filled($this->input('to'))
                ? $this->input('to')
                : now()->toDateString(),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $accountId = $this->input('account_id');

        if ($user === null) {
            return false;
        }

        if ($accountId === null || $accountId === '') {
            return true;
        }

        return Account::query()
            ->whereKey((int) $accountId)
            ->where('nation_id', (int) $user->nation_id)
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query->where('nation_id', (int) $this->user()->nation_id)
                ),
            ],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'string', Rule::in(array_keys(MemberAccountStatementService::typeOptions()))],
            'status' => ['nullable', 'string', Rule::in(array_keys(MemberAccountStatementService::statusOptions()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'Select one of your own accounts.',
            'from.before_or_equal' => 'The start date must be on or before the end date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
