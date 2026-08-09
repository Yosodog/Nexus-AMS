<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\PendingRequestRecoveryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleaseStalePendingRequestsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view-diagnostic-info') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PendingRequestRecoveryService $recovery): array
    {
        return [
            'type' => ['required', 'string', Rule::in($recovery->supportedTypes())],
            'older_than_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'confirm_release' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm_release.accepted' => 'Confirm that you reviewed these requests and checked any related transfers or messages before closing them.',
        ];
    }
}
