<?php

namespace App\Services\Audit;

final readonly class AuditEvaluationResult
{
    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, array<string, mixed>>  $evidence
     */
    public function __construct(
        public bool $matched,
        public array $warnings,
        public array $evidence,
        public int $durationMs,
    ) {}
}
