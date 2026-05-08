<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EnrollGrowthCirclesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->nation_id !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $nationId = (int) Auth::user()->nation_id;

        return [
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('nation_id', $nationId),
            ],
        ];
    }
}
