<?php

namespace App\Nel\Ast;

final class LiteralNode implements ExpressionNode
{
    public function __construct(public readonly mixed $value) {}
}
