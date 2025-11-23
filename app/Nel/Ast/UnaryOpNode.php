<?php

namespace App\Nel\Ast;

final class UnaryOpNode implements ExpressionNode
{
    public function __construct(
        public readonly string $operator,
        public readonly ExpressionNode $operand
    ) {}
}
