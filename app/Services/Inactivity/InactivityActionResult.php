<?php

namespace App\Services\Inactivity;

class InactivityActionResult
{
    public function __construct(
        public bool $notificationSent = false,
    ) {}
}
