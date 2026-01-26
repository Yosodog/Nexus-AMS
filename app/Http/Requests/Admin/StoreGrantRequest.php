<?php

namespace App\Http\Requests\Admin;

use App\Services\PWHelperService;
use Illuminate\Foundation\Http\FormRequest;

class StoreGrantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-grants') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', 'unique:grants,name'],
            'description' => ['required', 'string'],
            'money' => ['nullable', 'integer', 'min:0'],
            'is_enabled' => ['nullable', 'in:true,false,1,0,on,off'],
            'is_one_time' => ['nullable', 'in:true,false,1,0,on,off'],
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $rules[$resource] = ['nullable', 'integer', 'min:0'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'name.required' => 'A grant name is required.',
            'name.unique' => 'A grant with this name already exists.',
            'description.required' => 'A grant description is required.',
            'money.integer' => 'Money must be a whole number.',
            'money.min' => 'Money must be 0 or greater.',
        ];

        foreach (PWHelperService::resources(false) as $resource) {
            $messages["{$resource}.integer"] = 'Grant resources must be whole numbers.';
            $messages["{$resource}.min"] = 'Grant resources must be 0 or greater.';
        }

        return $messages;
    }
}
