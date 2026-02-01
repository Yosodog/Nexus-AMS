<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Logout;

class AuditLogout
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        $this->auditLogger->success(
            category: 'auth',
            action: 'logout',
            subject: $event->user,
            context: [
                'guard' => $event->guard,
            ],
            message: 'Logout successful.'
        );
    }
}
