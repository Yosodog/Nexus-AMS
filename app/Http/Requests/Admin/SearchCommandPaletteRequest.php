<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SearchCommandPaletteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-members') === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'query.min' => 'Enter at least two characters to search members.',
        ];
    }
}
