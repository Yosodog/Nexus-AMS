<?php

namespace App\Http\Requests\Admin\Payroll;

use App\Models\Nation;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePayrollMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('edit_payroll') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nation_id' => ['required', 'integer', 'min:1', 'exists:nations,id'],
            'payroll_grade_id' => ['required', 'integer', 'exists:payroll_grades,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $nationId = $this->input('nation_id');
            if (! $nationId) {
                return;
            }

            $nation = Nation::query()->select(['id', 'alliance_id'])->find($nationId);
            if (! $nation) {
                return;
            }

            $membershipService = app(AllianceMembershipService::class);
            if (! $membershipService->contains($nation->alliance_id)) {
                $validator->errors()->add('nation_id', 'Nation must belong to our alliance to be added to payroll.');
            }
        });
    }
}
