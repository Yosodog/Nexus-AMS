<?php

namespace App\Http\Requests\Admin;

use App\Services\PWHelperService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendManualWarAidRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-war-aid') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $resourceRules = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => ['nullable', 'integer', 'min:0']])
            ->all();

        return [
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            ...$resourceRules,
        ];
    }
}
