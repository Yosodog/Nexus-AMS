<?php

namespace App\Http\Requests\Admin;

use App\Models\MemberInactivityException;

class UpdateMemberInactivityExceptionRequest extends StoreMemberInactivityExceptionRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $exception = $this->route('memberInactivityException');

        return $exception instanceof MemberInactivityException
            && ($this->user()?->can('update', $exception) ?? false);
    }
}
