<?php

namespace App\Http\Requests\Admin;

use App\Services\PWHelperService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualOffshoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-offshores') ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', Rule::in(['main', 'offshore'])],
            'destination_type' => ['required', 'string', Rule::in(['main', 'offshore'])],
            'source_offshore_id' => ['nullable', 'integer', Rule::exists('offshores', 'id')],
            'destination_offshore_id' => ['nullable', 'integer', Rule::exists('offshores', 'id')],
            'note' => ['nullable', 'string', 'max:255'],
            'resources' => ['required', 'array'],
            'resources.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sourceType = $this->input('source_type');
            $destinationType = $this->input('destination_type');

            if ($sourceType === 'offshore' && ! $this->filled('source_offshore_id')) {
                $validator->errors()->add('source_offshore_id', 'Select an offshore to withdraw from.');
            }

            if ($destinationType === 'offshore' && ! $this->filled('destination_offshore_id')) {
                $validator->errors()->add('destination_offshore_id', 'Select an offshore to deposit to.');
            }

            if ($sourceType === $destinationType) {
                if ($sourceType !== 'offshore') {
                    $validator->errors()->add('destination_type', 'Source and destination must be different.');
                } elseif ($this->input('source_offshore_id') === $this->input('destination_offshore_id')) {
                    $validator->errors()->add('destination_offshore_id', 'Select a different offshore to deposit to.');
                }
            }

            $payload = $this->validatedResources();

            if (empty($payload)) {
                $validator->errors()->add('resources', 'Specify at least one resource to transfer.');
            }
        });
    }

    /**
     * @return array<string, float>
     */
    public function validatedResources(): array
    {
        $allowedResources = PWHelperService::resources();

        return collect($this->input('resources', []))
            ->filter(fn ($value, $resource) => in_array($resource, $allowedResources, true))
            ->map(fn ($value) => (float) $value)
            ->filter(fn (float $amount) => $amount > 0)
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function transferMeta(): array
    {
        return [
            'note' => $this->input('note'),
        ];
    }
}
