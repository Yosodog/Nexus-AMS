<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationPublicationPublishRequest extends FormRequest
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
            'preview_hash' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
