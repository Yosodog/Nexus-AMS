<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditTargetType;
use App\Services\Audit\AuditRuleDefinitionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;

class AuditRulePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-audits') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', Rule::in(AuditTargetType::values())],
            'definition' => ['required', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $definition = $this->input('definition');

        if (is_string($definition)) {
            try {
                $definition = json_decode($definition, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $definition = null;
            }
        }

        $this->merge(['definition' => $definition]);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $targetType = AuditTargetType::from((string) $this->input('target_type'));
                $inspection = app(AuditRuleDefinitionService::class)->inspect($this->input('definition'), $targetType);

                foreach ($inspection['errors'] as $error) {
                    $validator->errors()->add('definition', $error);
                }

                if ($inspection['normalized'] !== null) {
                    $this->merge(['definition' => $inspection['normalized']]);
                }
            },
        ];
    }
}
