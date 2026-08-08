<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;

class PasskeyAuditSubscriber
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handleRegistered(PasskeyRegistered $event): void
    {
        $this->auditLogger->success(
            category: 'authentication',
            action: 'passkey_registered',
            subject: $event->user,
            context: $this->passkeyContext($event->passkey),
            message: 'A passkey was registered.',
            actorOverride: $this->actorOverride($event->user),
        );
    }

    public function handleDeleted(PasskeyDeleted $event): void
    {
        $this->auditLogger->success(
            category: 'authentication',
            action: 'passkey_deleted',
            subject: $event->user,
            context: $this->passkeyContext($event->passkey),
            message: 'A passkey was deleted.',
            actorOverride: $this->actorOverride($event->user),
        );
    }

    public function handleVerified(PasskeyVerified $event): void
    {
        $purpose = match (true) {
            request()->routeIs('passkey.login') => 'login',
            request()->routeIs('passkey.confirm') => 'recent_authentication',
            default => 'verification',
        };

        $this->auditLogger->success(
            category: 'authentication',
            action: 'passkey_verified',
            subject: $event->user,
            context: [
                ...$this->passkeyContext($event->passkey),
                'purpose' => $purpose,
            ],
            message: 'A passkey was cryptographically verified.',
            actorOverride: $this->actorOverride($event->user),
        );
    }

    public function handleLogin(Login $event): void
    {
        if (! request()->routeIs('passkey.login')) {
            return;
        }

        $this->auditLogger->success(
            category: 'authentication',
            action: 'passkey_login',
            subject: $event->user,
            context: [
                'guard' => $event->guard,
                'remember' => (bool) $event->remember,
            ],
            message: 'A user signed in with a passkey.',
            actorOverride: $this->actorOverride($event->user),
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PasskeyRegistered::class => 'handleRegistered',
            PasskeyDeleted::class => 'handleDeleted',
            PasskeyVerified::class => 'handleVerified',
            Login::class => 'handleLogin',
        ];
    }

    /**
     * @return array{passkey_id: int|string|null, authenticator: string|null}
     */
    private function passkeyContext(Passkey $passkey): array
    {
        return [
            'passkey_id' => $passkey->getKey(),
            'authenticator' => $passkey->authenticator,
        ];
    }

    /**
     * @return array{type: string, id: int|null, name: string|null}
     */
    private function actorOverride(Authenticatable $user): array
    {
        $identifier = $user->getAuthIdentifier();
        $name = data_get($user, 'name');

        return [
            'type' => 'user',
            'id' => is_numeric($identifier) ? (int) $identifier : null,
            'name' => is_scalar($name) ? (string) $name : null,
        ];
    }
}
