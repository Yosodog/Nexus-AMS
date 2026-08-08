<?php

namespace App\Domain\Federation\Services;

use App\Domain\Federation\Cryptography\FederationCryptography;
use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Transport\PeerOrigin;
use App\Models\FederationIdentity;
use App\Models\FederationIdentityKey;
use App\Models\FederationLink;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FederationIdentityService
{
    public function __construct(
        private readonly FederationCryptography $cryptography,
        private readonly AuditLogger $audit,
    ) {}

    public function enable(): FederationIdentity
    {
        if (! (bool) config('federation.enabled', false)) {
            throw ValidationException::withMessages([
                'federation' => 'Federation is disabled by the server configuration.',
            ]);
        }

        return Cache::lock('federation:identity:initialize', 15)->block(5, function (): FederationIdentity {
            $identity = DB::transaction(function (): FederationIdentity {
                $identity = FederationIdentity::query()->lockForUpdate()->first();

                if (! $identity instanceof FederationIdentity) {
                    $origin = PeerOrigin::fromUrl((string) config('app.url'));
                    $identity = FederationIdentity::query()->create([
                        'id' => (string) Str::ulid(),
                        'origin' => $origin->value(),
                        'display_name' => (string) config('app.name', 'Nexus AMS'),
                        'ownership_epoch' => 1,
                        'enabled' => true,
                        'enabled_at' => now(),
                    ]);
                } else {
                    $identity->forceFill([
                        'enabled' => true,
                        'enabled_at' => $identity->enabled_at ?? now(),
                        'disabled_at' => null,
                    ])->save();
                }

                if (! $identity->keys()->where('active_key', 1)->exists()) {
                    $this->createKey($identity, FederationKeyStatus::Active, true);
                }

                return $identity->fresh('activeKey');
            }, attempts: 5);

            $this->audit->success('federation', 'identity.enabled', $identity, [
                'installation_id' => $identity->id,
                'ownership_epoch' => $identity->ownership_epoch,
            ]);

            return $identity;
        });
    }

    public function disable(): FederationIdentity
    {
        $identity = FederationIdentity::query()->firstOrFail();
        $identity->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();

        $this->audit->success('federation', 'identity.disabled', $identity, [
            'installation_id' => $identity->id,
        ]);

        return $identity;
    }

    public function initiateRoutineRotation(): FederationIdentityKey
    {
        $newKey = DB::transaction(function (): FederationIdentityKey {
            $identity = FederationIdentity::query()->lockForUpdate()->firstOrFail();
            $oldKey = $identity->keys()->where('active_key', 1)->lockForUpdate()->firstOrFail();

            if ($identity->keys()->where('status', FederationKeyStatus::Pending->value)->exists()) {
                throw ValidationException::withMessages([
                    'rotation' => 'A key rotation is already awaiting peer acknowledgment.',
                ]);
            }

            $newKey = $this->createKey($identity, FederationKeyStatus::Pending, false);
            $statement = CanonicalJson::encode([
                'installation_id' => $identity->id,
                'ownership_epoch' => (int) $identity->ownership_epoch,
                'old_key_id' => $oldKey->id,
                'new_key' => [
                    'key_id' => $newKey->id,
                    'generation' => (int) $newKey->generation,
                    'signing_public_key' => $newKey->signing_public_key,
                    'box_public_key' => $newKey->box_public_key,
                    'signing_fingerprint' => $newKey->signing_fingerprint,
                    'box_fingerprint' => $newKey->box_fingerprint,
                ],
                'issued_at' => now()->utc()->toIso8601String(),
            ]);
            $newKey->forceFill([
                'rotation_statement' => CanonicalJson::encode([
                    'statement' => $statement,
                    'old_signature' => $this->cryptography->sign($statement, $oldKey->signing_private_key),
                    'new_signature' => $this->cryptography->sign($statement, $newKey->signing_private_key),
                ]),
            ])->save();

            return $newKey;
        }, attempts: 5);

        $this->audit->success('federation', 'identity.rotation_started', $newKey, [
            'key_id' => $newKey->id,
            'generation' => $newKey->generation,
        ]);

        return $newKey;
    }

    public function activateRotation(FederationIdentityKey $newKey): FederationIdentityKey
    {
        $activated = DB::transaction(function () use ($newKey): FederationIdentityKey {
            $identity = FederationIdentity::query()->lockForUpdate()->firstOrFail();
            $pending = $identity->keys()->lockForUpdate()->findOrFail($newKey->id);

            if ($pending->status !== FederationKeyStatus::Pending) {
                throw ValidationException::withMessages(['rotation' => 'This key is not pending activation.']);
            }

            $oldKey = $identity->keys()->where('active_key', 1)->lockForUpdate()->firstOrFail();
            $graceDays = max((int) config('federation.retiring_key_grace_days', 30), 1);
            $oldKey->forceFill([
                'active_key' => null,
                'status' => FederationKeyStatus::Retiring,
                'retiring_at' => now(),
                'purge_after' => now()->addDays($graceDays),
            ])->save();
            $pending->forceFill([
                'active_key' => 1,
                'status' => FederationKeyStatus::Active,
                'activated_at' => now(),
            ])->save();

            return $pending;
        }, attempts: 5);

        $this->audit->success('federation', 'identity.rotation_activated', $activated, [
            'key_id' => $activated->id,
            'generation' => $activated->generation,
        ]);

        return $activated;
    }

    public function markCompromised(FederationIdentityKey $key): FederationIdentityKey
    {
        $replacement = DB::transaction(function () use ($key): FederationIdentityKey {
            $identity = FederationIdentity::query()->lockForUpdate()->firstOrFail();
            $compromised = $identity->keys()->lockForUpdate()->findOrFail($key->id);
            $compromised->forceFill([
                'active_key' => null,
                'status' => FederationKeyStatus::Compromised,
                'compromised_at' => now(),
            ])->save();
            FederationLink::query()
                ->whereIn('status', [FederationLinkStatus::Active->value, FederationLinkStatus::PendingLocal->value])
                ->update([
                    'status' => FederationLinkStatus::Suspended->value,
                    'suspension_reason_code' => 'local_key_compromised',
                    'suspended_at' => now(),
                    'updated_at' => now(),
                ]);

            return $this->createKey($identity, FederationKeyStatus::Pending, false);
        }, attempts: 5);

        $this->audit->success('federation', 'identity.key_compromised', $replacement, [
            'compromised_key_id' => $key->id,
            'replacement_key_id' => $replacement->id,
        ]);

        return $replacement;
    }

    public function transferOwnership(): FederationIdentityKey
    {
        DB::transaction(function (): void {
            $identity = FederationIdentity::query()->lockForUpdate()->firstOrFail();
            $identity->increment('ownership_epoch');
        });

        return $this->initiateRoutineRotation();
    }

    private function createKey(
        FederationIdentity $identity,
        FederationKeyStatus $status,
        bool $active,
    ): FederationIdentityKey {
        $generation = ((int) $identity->keys()->max('generation')) + 1;
        $material = $this->cryptography->generateKeyMaterial();

        return $identity->keys()->create([
            ...$material,
            'id' => (string) Str::ulid(),
            'generation' => $generation,
            'status' => $status,
            'active_key' => $active ? 1 : null,
            'activated_at' => $active ? now() : null,
        ]);
    }
}
