<?php

namespace App\Domain\Federation\DTO;

final readonly class OpenedEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public FederationEnvelope $envelope,
        public ProtectedHeader $header,
        public string $rawPayload,
        public array $payload,
    ) {}
}
