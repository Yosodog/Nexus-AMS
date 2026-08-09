<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationPublicationRevokeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('publish-federated-war-plans') === true
            && $this->user()?->can('manage-war-room') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'min:3', 'max:64'],
            'recipient_installation_id' => ['sometimes', 'required', 'ulid'],
        ];
    }
}
