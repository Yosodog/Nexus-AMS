<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMainBankCredentialsRequest extends FormRequest
{
    /**
     * @var string[]
     */
    protected $dontFlash = ['api_key', 'mutation_key'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-offshores') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'required_without:mutation_key', 'string', 'ascii', 'size:20'],
            'mutation_key' => ['nullable', 'required_without:api_key', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{api_key?: string, mutation_key?: string}
     */
    public function credentials(): array
    {
        return collect($this->validated())
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key.required_without' => 'Enter an API key or a mutation key to update.',
            'api_key.size' => 'The API key must be exactly 20 characters.',
            'mutation_key.required_without' => 'Enter an API key or a mutation key to update.',
        ];
    }
}
