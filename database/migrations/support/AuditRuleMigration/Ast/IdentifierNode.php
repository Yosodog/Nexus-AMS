<?php

namespace Database\Migrations\Support\AuditRuleMigration\Ast;

final readonly class IdentifierNode implements ExpressionNode
{
    /**
     * @param  list<string>  $segments
     */
    public function __construct(public array $segments) {}

    public function path(): string
    {
        return implode('.', $this->segments);
    }
}
