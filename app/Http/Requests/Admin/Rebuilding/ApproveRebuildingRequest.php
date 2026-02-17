<?php

namespace App\Http\Requests\Admin\Rebuilding;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRebuildingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-rebuilding') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'review_note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
