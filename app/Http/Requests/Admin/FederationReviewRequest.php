<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->routeIs('admin.federation.received.reject')) {
            return $this->user()?->can('review-federated-war-plans') === true;
        }

        return $this->user()?->can('review-federated-war-plans') === true
            && $this->user()?->can('import-federated-war-plans') === true
            && $this->user()?->can('manage-war-room') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
