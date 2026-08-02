<?php

namespace Database\Migrations\Support\AuditRuleMigration\Ast;

final readonly class FunctionCallNode implements ExpressionNode
{
    /**
     * @param  list<ExpressionNode>  $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {}
}
