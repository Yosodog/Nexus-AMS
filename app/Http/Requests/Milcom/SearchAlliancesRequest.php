<?php

namespace App\Http\Requests\Milcom;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchAlliancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-war-room') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'required_without:ids', 'string', 'min:2', 'max:100'],
            'ids' => ['nullable', 'required_without:q', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.min' => 'Type at least 2 characters to search alliances.',
            'q.required_without' => 'Type an alliance name, acronym, or ID.',
            'ids.required_without' => 'Type an alliance name, acronym, or ID.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('ids', []);

        if (is_string($ids)) {
            $ids = collect(preg_split('/[\s,]+/', $ids, -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn (string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        $this->merge([
            'q' => trim((string) $this->input('q', '')),
            'ids' => is_array($ids) ? $ids : [],
        ]);
    }
}
