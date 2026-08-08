<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class SignedTenantControlRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
        public string $nonce,
    ) {}
}
