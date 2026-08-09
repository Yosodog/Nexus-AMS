<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationPublicationPreviewRequest extends FormRequest
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
            'operation_id' => ['required', 'integer', 'exists:milcom_operations,id'],
            'coalition_id' => ['required', 'ulid', 'exists:federation_coalitions,id'],
            'publication_id' => ['nullable', 'ulid', 'exists:federation_publications,id'],
            'recipient_link_ids' => ['required', 'array', 'min:1', 'max:500'],
            'recipient_link_ids.*' => ['ulid', 'distinct', 'exists:federation_links,id'],
            'objective_ids' => ['required', 'array', 'min:1', 'max:500'],
            'objective_ids.*' => ['integer', 'distinct', 'exists:milcom_objectives,id'],
            'title' => ['required', 'string', 'max:255'],
            'wave_label' => ['nullable', 'string', 'max:100'],
            'recipient_instructions' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['required', 'date', 'after:now'],
        ];
    }
}
