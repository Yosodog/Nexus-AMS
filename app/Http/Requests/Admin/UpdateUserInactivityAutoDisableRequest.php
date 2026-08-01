<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserInactivityAutoDisableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (bool) $user->is_admin
            && $user->hasPermission('edit-users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_inactivity_auto_disable_enabled' => ['required', 'boolean'],
            'user_inactivity_auto_disable_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
