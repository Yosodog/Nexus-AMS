<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Services\Rules\RuleTreeKernel;
use InvalidArgumentException;

final class AuditRuleDefinitionService
{
    public function __construct(
        private readonly RuleTreeKernel $kernel,
        private readonly AuditFieldRegistry $fields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $definition, AuditTargetType $targetType): array
    {
        $inspection = $this->inspect($definition, $targetType);

        if ($inspection['errors'] !== [] || $inspection['normalized'] === null) {
            throw new InvalidArgumentException(implode(' ', $inspection['errors']));
        }

        return $inspection['normalized'];
    }

    /**
     * @return array{normalized: array<string, mixed>|null, errors: array<int, string>}
     */
    public function inspect(mixed $definition, AuditTargetType $targetType): array
    {
        return $this->kernel->inspectAuditDefinition($definition, $this->fields->forTarget($targetType));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function hasCriteria(array $definition): bool
    {
        return isset($definition['criteria']['rules'])
            && is_array($definition['criteria']['rules'])
            && $definition['criteria']['rules'] !== [];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function summarize(array $definition, AuditTargetType $targetType): string
    {
        $catalog = $this->fields->forTarget($targetType);
        $criteria = $this->kernel->describeTree($definition['criteria'] ?? [], $catalog);
        $exceptions = $definition['exceptions']['rules'] ?? [];

        if ($criteria === 'No conditions') {
            return 'No alert conditions have been added yet.';
        }

        if (! is_array($exceptions) || $exceptions === []) {
            return 'Alert when '.$this->lowercaseFirst($criteria).'.';
        }

        $exceptionSummary = $this->kernel->describeTree($definition['exceptions'], $catalog);

        return 'Alert when '.$this->lowercaseFirst($criteria).'. Except when '.$this->lowercaseFirst($exceptionSummary).'.';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function fingerprint(AuditTargetType $targetType, array $definition): string
    {
        return hash('sha256', json_encode([
            'target_type' => $targetType->value,
            'definition' => $this->kernel->canonicalizeAuditDefinition($definition),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    public function referencedFields(array $definition): array
    {
        return $this->kernel->referencedFields($definition);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    public function memberSafeDetails(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $evidence = array_values(array_filter(
            is_array($details['evidence'] ?? null) ? $details['evidence'] : [],
            static fn (mixed $item): bool => is_array($item) && ($item['member_safe'] ?? false) === true,
        ));

        return [
            'rule_revision' => $details['rule_revision'] ?? null,
            'summary' => $details['summary'] ?? null,
            'evidence' => array_map(static function (array $item): array {
                return [
                    'scope' => $item['scope'] ?? 'criteria',
                    'condition' => $item['condition'] ?? '',
                    'field_label' => $item['field_label'] ?? '',
                    'operator_label' => $item['operator_label'] ?? '',
                    'observed' => $item['observed'] ?? null,
                    'observed_display' => $item['observed_display'] ?? 'Unavailable',
                    'expected' => $item['expected'] ?? null,
                    'expected_display' => $item['expected_display'] ?? '',
                    'matched' => (bool) ($item['matched'] ?? false),
                ];
            }, $evidence),
            'evaluated_at' => $details['evaluated_at'] ?? null,
        ];
    }

    private function lowercaseFirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
