<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;

class AuditLogin
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        $this->auditLogger->success(
            category: 'auth',
            action: 'login',
            subject: $event->user,
            context: [
                'guard' => $event->guard,
                'remember' => $event->remember,
            ],
            message: 'Login successful.'
        );
    }
}
