<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaviconRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-diagnostic-info') ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'favicon' => ['required', 'file', 'mimes:png,ico,svg,jpg,jpeg', 'max:1024'],
        ];
    }
}
