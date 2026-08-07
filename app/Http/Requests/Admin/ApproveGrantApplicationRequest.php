<?php

namespace App\Http\Requests\Admin;

use App\DataTransferObjects\GrantDecisionData;
use App\Enums\GrantDecisionReason;
use App\Support\GrantDecisionText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApproveGrantApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision_explanation' => ['nullable', 'string', 'max:1000'],
            'decision_internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision_explanation.max' => 'The member-visible explanation may not exceed 1,000 characters.',
            'decision_internal_note.max' => 'The internal note may not exceed 2,000 characters.',
        ];
    }

    public function decision(): GrantDecisionData
    {
        return new GrantDecisionData(
            reason: GrantDecisionReason::Approved,
            memberExplanation: $this->validated('decision_explanation'),
            internalNote: $this->validated('decision_internal_note'),
        );
    }

    protected function prepareForValidation(): void
    {
        $memberExplanation = $this->input('decision_explanation');
        $internalNote = $this->input('decision_internal_note');

        $this->merge([
            'decision_explanation' => is_string($memberExplanation)
                ? GrantDecisionText::sanitize($memberExplanation)
                : $memberExplanation,
            'decision_internal_note' => is_string($internalNote)
                ? GrantDecisionText::sanitize($internalNote)
                : $internalNote,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $memberExplanation = $this->input('decision_explanation');

                if (
                    is_string($memberExplanation)
                    && GrantDecisionText::containsRestrictedMemberContent($memberExplanation)
                ) {
                    $validator->errors()->add(
                        'decision_explanation',
                        'Keep security, fraud, and internal risk details in the staff-only note.',
                    );
                }
            },
        ];
    }
}
