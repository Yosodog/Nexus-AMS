<?php

namespace App\Http\Requests;

class StoreAccountStatementExportRequest extends AccountStatementRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['account_id'][0] = 'required';

        return $rules;
    }
}
