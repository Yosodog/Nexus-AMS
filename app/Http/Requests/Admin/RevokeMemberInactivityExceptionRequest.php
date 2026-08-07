<?php

namespace App\Http\Requests\Admin;

use App\Models\MemberInactivityException;
use Illuminate\Foundation\Http\FormRequest;

class RevokeMemberInactivityExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $exception = $this->route('memberInactivityException');

        return $exception instanceof MemberInactivityException
            && ($this->user()?->can('delete', $exception) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'revocation_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
