<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use App\Models\AuditRule;
use App\Services\Audit\AuditImpactConfirmationService;
use App\Services\Audit\AuditRuleDefinitionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;

class AuditRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-audits') === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'remediation_guidance' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'target_type' => ['required', 'string', Rule::in(AuditTargetType::values())],
            'priority' => ['required', 'string', Rule::in(AuditPriority::values())],
            'definition' => ['required', 'array'],
            'enabled' => ['required', 'boolean'],
            'impact_confirmation_token' => ['nullable', 'string', 'max:10000'],
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

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->normalizedText('description'),
            'remediation_guidance' => $this->normalizedText('remediation_guidance'),
            'admin_notes' => $this->normalizedText('admin_notes'),
            'definition' => $definition,
            'enabled' => $this->boolean('enabled'),
        ]);
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
                $definitions = app(AuditRuleDefinitionService::class);
                $inspection = $definitions->inspect($this->input('definition'), $targetType);

                foreach ($inspection['errors'] as $error) {
                    $validator->errors()->add('definition', $error);
                }

                if ($inspection['normalized'] === null) {
                    return;
                }

                $this->merge(['definition' => $inspection['normalized']]);

                if ($this->boolean('enabled') && ! $definitions->hasCriteria($inspection['normalized'])) {
                    $validator->errors()->add('definition', 'Add at least one “Alert when” condition before enabling this rule.');

                    return;
                }

                if (! $this->requiresImpactConfirmation($targetType, $inspection['normalized'], $definitions)) {
                    return;
                }

                try {
                    app(AuditImpactConfirmationService::class)->assertValid(
                        $this->input('impact_confirmation_token'),
                        $this->user(),
                        $targetType,
                        $inspection['normalized'],
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function requiresImpactConfirmation(
        AuditTargetType $targetType,
        array $definition,
        AuditRuleDefinitionService $definitions,
    ): bool {
        if (! $this->boolean('enabled')) {
            return false;
        }

        $existing = $this->route('auditRule');

        if (! $existing instanceof AuditRule || ! $existing->enabled || ! is_array($existing->definition)) {
            return true;
        }

        return $existing->target_type !== $targetType
            || $definitions->fingerprint($existing->target_type, $existing->definition)
                !== $definitions->fingerprint($targetType, $definition);
    }

    private function normalizedText(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
