<?php

namespace App\Http\Requests\Admin\Rebuilding;

use Illuminate\Foundation\Http\FormRequest;

class MarkRebuildingIneligibleRequest extends FormRequest
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
            'nation_id' => ['required', 'integer', 'exists:nations,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
