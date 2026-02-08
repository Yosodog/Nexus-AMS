<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Services\AllianceMembershipService;
use App\Services\PWHelperService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'from' => ['required', 'integer', 'exists:accounts,id'],
            'to' => ['required'],
        ];

        $resources = PWHelperService::resources();

        foreach ($resources as $resource) {
            $rules[$resource] = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];
        }

        $to = $this->input('to');

        if ($to === 'nation') {
            $rules['to'][] = Rule::in(['nation']);
        } elseif ($this->isLoanDestination()) {
            $rules['to'][] = ['string', 'regex:/^loan_\\d+$/'];
            $rules['money'][] = 'required';
            $rules['money'][] = 'min:0.01';
        } else {
            $rules['to'][] = 'integer';
            $rules['to'][] = Rule::exists('accounts', 'id');
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowedKeys = array_merge(['from', 'to', '_token'], PWHelperService::resources());
            $inputKeys = array_keys($this->all());

            foreach ($inputKeys as $key) {
                if (! in_array($key, $allowedKeys, true)) {
                    $validator->errors()->add($key, 'Unexpected field.');
                }
            }

            $hasResources = false;

            $resources = PWHelperService::resources();

            foreach ($resources as $resource) {
                $value = $this->input($resource);

                if ($value === null || $value === '') {
                    continue;
                }

                if ((float) $value > 0) {
                    $hasResources = true;
                    break;
                }
            }

            if (! $hasResources) {
                $validator->errors()->add(
                    'transfer',
                    'You must transfer at least one resource with an amount greater than 0.'
                );
            }

            if ($this->isLoanDestination()) {
                foreach (array_diff($resources, ['money']) as $resource) {
                    $value = $this->input($resource);

                    if ($value !== null && $value !== '' && (float) $value > 0) {
                        $validator->errors()->add(
                            $resource,
                            'Loan repayments can only be made with money.'
                        );
                    }
                }
            }

            $to = $this->input('to');

            if (is_numeric($to)) {
                $fromAccountId = (int) $this->input('from');
                $toAccountId = (int) $to;

                if ($fromAccountId === $toAccountId) {
                    $validator->errors()->add('to', 'Cannot transfer resources to the same account.');

                    return;
                }

                $fromAccount = Account::query()->with('nation')->find($fromAccountId);
                $toAccount = Account::query()->with('nation')->find($toAccountId);

                if (! $fromAccount || ! $toAccount) {
                    return;
                }

                if ($toAccount->nation_id !== $fromAccount->nation_id) {
                    $membershipService = app(AllianceMembershipService::class);
                    $fromAllianceId = $fromAccount->nation?->alliance_id;
                    $toAllianceId = $toAccount->nation?->alliance_id;

                    if (! $membershipService->contains($fromAllianceId) || ! $membershipService->contains($toAllianceId)) {
                        $validator->errors()->add('to', 'Transfers are only allowed within your alliance.');
                    }
                }
            }
        });
    }

    private function isLoanDestination(): bool
    {
        $to = $this->input('to');

        return is_string($to) && str_starts_with($to, 'loan_');
    }
}
