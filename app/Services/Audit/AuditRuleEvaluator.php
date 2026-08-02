<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Services\Rules\RuleTreeKernel;
use Illuminate\Support\Carbon;

final class AuditRuleEvaluator
{
    public function __construct(
        private readonly RuleTreeKernel $kernel,
        private readonly AuditFieldRegistry $fields,
        private readonly AuditRuleDefinitionService $definitions,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    public function evaluate(
        AuditTargetType $targetType,
        array $definition,
        array $context,
        ?Carbon $evaluatedAt = null,
    ): AuditEvaluationResult {
        $startedAt = hrtime(true);
        $evaluatedAt ??= now();
        $catalog = $this->fields->forTarget($targetType);

        if (! $this->definitions->hasCriteria($definition)) {
            return new AuditEvaluationResult(false, [], [], $this->durationMs($startedAt));
        }

        $criteria = $this->kernel->evaluateGroup(
            $definition['criteria'],
            $context,
            $catalog,
            'criteria',
            $evaluatedAt,
        );
        $exceptionRules = $definition['exceptions']['rules'] ?? [];
        $exceptions = is_array($exceptionRules) && $exceptionRules !== []
            ? $this->kernel->evaluateGroup(
                $definition['exceptions'],
                $context,
                $catalog,
                'exception',
                $evaluatedAt,
            )
            : ['matched' => false, 'warnings' => [], 'evidence' => []];

        return new AuditEvaluationResult(
            matched: $criteria['matched'] && ! $exceptions['matched'],
            warnings: array_values(array_unique([...$criteria['warnings'], ...$exceptions['warnings']])),
            evidence: [...$criteria['evidence'], ...$exceptions['evidence']],
            durationMs: $this->durationMs($startedAt),
        );
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
