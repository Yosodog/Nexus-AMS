<?php

namespace App\Services\Discord\Relay;

use App\Services\Discord\DiscordConnectionContext;

final readonly class VerifiedDiscordRelayProof
{
    public function __construct(
        public DiscordConnectionContext $connection,
        public string $type,
        public string $action,
        public string $idempotencyKey,
        public string $keyId,
        public string $issuedAt,
        public string $expiresAt,
        public ?string $interactionId = null,
        public ?string $userId = null,
        public ?string $command = null,
        public ?string $nonce = null,
    ) {}
}
