<?php

namespace Database\Migrations\Support\AuditRuleMigration;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public mixed $value,
        public int $position,
    ) {}
}
