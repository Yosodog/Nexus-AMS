<?php

namespace App\Nel\Ast;

final readonly class LiteralNode implements ExpressionNode
{
    public function __construct(public mixed $value) {}
}
