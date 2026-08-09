<?php

namespace App\Services\Discord;

use RuntimeException;

final class DiscordProfileSyncException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly ?string $userAction = null,
    ) {
        parent::__construct($message, $status);
    }
}
