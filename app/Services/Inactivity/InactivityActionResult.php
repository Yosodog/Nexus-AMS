<?php

namespace App\Services\Inactivity;

readonly class InactivityActionResult
{
    public function __construct(
        public bool $notificationSent = false,
    ) {}
}
