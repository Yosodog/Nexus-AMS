<?php

namespace App\Http\Requests\Admin;

use App\Enums\MemberInactivityAutomation;
use App\Enums\MemberInactivityExceptionCategory;
use App\Models\MemberInactivityException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberInactivityExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', MemberInactivityException::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(MemberInactivityExceptionCategory::class)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'timezone' => ['required', 'string', 'timezone', 'max:64'],
            'member_reason' => ['required', 'string', 'max:2000'],
            'private_notes' => ['nullable', 'string', 'max:10000'],
            'affected_automations' => ['required', 'array', 'min:1'],
            'affected_automations.*' => [
                'required',
                'string',
                'distinct',
                Rule::enum(MemberInactivityAutomation::class),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'The exception must end after it starts.',
            'affected_automations.required' => 'Select at least one automation to suppress.',
            'affected_automations.min' => 'Select at least one automation to suppress.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('timezone')) {
            $this->merge(['timezone' => (string) config('app.timezone', 'UTC')]);
        }
    }
}
