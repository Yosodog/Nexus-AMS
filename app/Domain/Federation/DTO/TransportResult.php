<?php

namespace App\Domain\Federation\DTO;

final readonly class TransportResult
{
    public function __construct(
        public int $status,
        public string $body,
        public string $correlationId,
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === 202;
    }

    public function isRetryable(): bool
    {
        return $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
