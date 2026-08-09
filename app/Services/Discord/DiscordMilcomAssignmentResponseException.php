<?php

namespace App\Services\Discord;

use RuntimeException;

final class DiscordMilcomAssignmentResponseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?string $userAction = null,
    ) {
        parent::__construct($message);
    }
}
