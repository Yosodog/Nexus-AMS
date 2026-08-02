<?php

namespace Database\Migrations\Support\AuditRuleMigration;

use RuntimeException;

final class UnsupportedExpressionException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
