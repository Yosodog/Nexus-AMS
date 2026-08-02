<?php

namespace Database\Migrations\Support\AuditRuleMigration\Ast;

final readonly class BinaryOpNode implements ExpressionNode
{
    public function __construct(
        public ExpressionNode $left,
        public string $operator,
        public ExpressionNode $right,
    ) {}
}
