<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationCapabilityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-coalitions') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'link_id' => ['required', 'ulid', 'exists:federation_links,id'],
            'direction' => ['required', 'in:inbound,outbound'],
            'state' => ['required', 'in:active,revoked'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
