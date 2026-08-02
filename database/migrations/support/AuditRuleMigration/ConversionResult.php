<?php

namespace Database\Migrations\Support\AuditRuleMigration;

final readonly class ConversionResult
{
    /**
     * @param  array<string, mixed>|null  $definition
     * @param  array{code: string, message: string, context: array<string, scalar|null>}|null  $unsupported
     */
    private function __construct(
        public bool $succeeded,
        public ?array $definition,
        public ?array $unsupported,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function success(array $definition): self
    {
        return new self(true, $definition, null);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function unsupported(string $code, string $message, array $context = []): self
    {
        return new self(false, null, [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
