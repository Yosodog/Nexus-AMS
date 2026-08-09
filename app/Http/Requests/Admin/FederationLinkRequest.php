<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FederationLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-federation') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'origin' => ['sometimes', 'required', 'string', 'max:512'],
            'new_origin' => ['sometimes', 'required', 'string', 'max:512'],
            'fingerprints_confirmed' => ['sometimes', 'accepted'],
            'reason_code' => ['sometimes', 'required', 'string', 'min:3', 'max:64'],
        ];
    }
}
