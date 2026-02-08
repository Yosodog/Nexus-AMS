<?php

namespace App\Http\Requests;

use App\Services\PWHelperService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SellMarketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $accountRule = Rule::exists('accounts', 'id');

        if ($this->user()) {
            $accountRule = $accountRule->where('nation_id', $this->user()->nation_id);
        }

        return [
            'account_id' => ['required', 'integer', $accountRule],
            'resource' => ['required', 'string', Rule::in(PWHelperService::resources(false))],
            'amount' => ['required', 'numeric', 'min:1'],
        ];
    }
}
