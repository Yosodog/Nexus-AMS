<?php

namespace Database\Migrations\Support\AuditRuleMigration\Ast;

final readonly class UnaryOpNode implements ExpressionNode
{
    public function __construct(
        public string $operator,
        public ExpressionNode $operand,
    ) {}
}
