<?php

namespace App\Http\Requests;

use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Http\FormRequest;

class MemberTransferSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $membershipService = app(AllianceMembershipService::class);

        return $membershipService->contains($user->nation?->alliance_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }
}
