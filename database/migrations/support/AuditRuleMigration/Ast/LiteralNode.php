<?php

namespace Database\Migrations\Support\AuditRuleMigration\Ast;

final readonly class LiteralNode implements ExpressionNode
{
    public function __construct(public mixed $value) {}
}
