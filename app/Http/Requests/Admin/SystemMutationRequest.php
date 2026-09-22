<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SystemMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->is_admin && ! $user->disabled && $user->can('manage-system'));
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'accepted'],
            'command' => ['prohibited'],
            'path' => ['prohibited'],
            'repository' => ['prohibited'],
            'service' => ['prohibited'],
            'target_release_id' => ['prohibited'],
            'url' => ['prohibited'],
        ];
    }
}
