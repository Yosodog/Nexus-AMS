<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Arr;
use Laravel\Fortify\Fortify;

class AuditLoginFailed
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Handle the event.
     */
    public function handle(Failed $event): void
    {
        $usernameField = Fortify::username();
        $attempted = Arr::get($event->credentials, $usernameField)
            ?? Arr::get($event->credentials, 'email')
            ?? Arr::get($event->credentials, 'name');

        $actorOverride = $event->user
            ? [
                'type' => 'user',
                'id' => $event->user->getAuthIdentifier(),
                'name' => $event->user->name ?? null,
            ]
            : [
                'type' => 'system',
                'id' => null,
                'name' => null,
            ];

        $this->auditLogger->record(
            category: 'auth',
            action: 'login_failed',
            outcome: 'failure',
            severity: 'warning',
            subject: $event->user,
            context: [
                'guard' => $event->guard,
                'attempted_username' => is_string($attempted) ? $attempted : null,
            ],
            message: 'Login failed.',
            actorOverride: $actorOverride
        );
    }
}
