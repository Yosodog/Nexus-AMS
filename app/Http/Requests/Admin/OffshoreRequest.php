<?php

namespace App\Http\Requests\Admin;

use App\Models\OffshoreGuardrail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class OffshoreRequest extends FormRequest
{
    /**
     * Ensure sensitive fields are never flashed back to the session on validation errors.
     *
     * @var string[]
     */
    protected $dontFlash = ['api_key', 'mutation_key'];

    abstract protected function isUpdate(): bool;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $nameRule = $this->isUpdate() ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'];
        $allianceRule = $this->isUpdate() ? ['sometimes', 'integer', 'exists:alliances,id'] : ['required', 'integer', 'exists:alliances,id'];
        $apiKeyRule = $this->isUpdate() ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'];
        $mutationKeyRule = $this->isUpdate() ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        return [
            'name' => $nameRule,
            'alliance_id' => $allianceRule,
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'api_key' => $apiKeyRule,
            'mutation_key' => $mutationKeyRule,
            'guardrails' => ['sometimes', 'array'],
            'guardrails.*.resource' => ['required_with:guardrails', 'string', Rule::in(OffshoreGuardrail::RESOURCES)],
            'guardrails.*.minimum_amount' => ['required_with:guardrails', 'numeric', 'min:0'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->except(['guardrails']);
    }

    public function guardrails(): ?array
    {
        if (! $this->has('guardrails')) {
            return null;
        }

        return collect($this->input('guardrails', []))
            ->map(fn(array $guardrail) => [
                'resource' => $guardrail['resource'],
                'minimum_amount' => (float) $guardrail['minimum_amount'],
            ])
            ->values()
            ->all();
    }
}
