<?php

namespace App\Http\Requests\Admin\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollGradeRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payroll_grades', 'name')->ignore($this->route('payrollGrade')),
            ],
            'weekly_amount' => ['required', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
