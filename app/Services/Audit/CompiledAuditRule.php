<?php

namespace App\Services\Audit;

use App\Models\AuditRule;
use App\Nel\Ast\ExpressionNode;

final readonly class CompiledAuditRule
{
    public function __construct(
        public AuditRule $rule,
        public ExpressionNode $expression,
    ) {}
}
