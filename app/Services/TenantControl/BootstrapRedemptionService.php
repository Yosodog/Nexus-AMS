<?php

declare(strict_types=1);

namespace App\Services\TenantControl;

use App\Contracts\BootstrapTokenIntrospector;
use App\DataTransferObjects\BootstrapClaims;
use App\DataTransferObjects\BootstrapLocalIdentity;
use App\DataTransferObjects\BootstrapRedemptionResult;
use App\Enums\BootstrapRedemptionMode;
use App\Enums\TenantBootstrapAction;
use App\Enums\TenantCallbackStatus;
use App\Enums\TenantCallbackType;
use App\Exceptions\BootstrapRedemptionException;
use App\Models\BootstrapRedemption;
use App\Models\Nation;
use App\Models\Role;
use App\Models\TenantCallbackDelivery;
use App\Models\User;
use App\Rules\UniqueCanonicalUsername;
use App\Services\AuditLogger;
use App\Services\RuntimeBuildMetadata;
use App\Services\RuntimeCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final readonly class BootstrapRedemptionService
{
    private const ADMIN_ROLE = 'default admin';

    public function __construct(
        private RuntimeCapabilities $capabilities,
        private RuntimeBuildMetadata $build,
        private BootstrapTokenIntrospector $introspector,
        private AuditLogger $audit,
    ) {}

    public function redeem(
        string $tokenHash,
        BootstrapLocalIdentity $identity,
    ): BootstrapRedemptionResult {
        if (! $this->capabilities->acceptsBootstrapRedemption() || ! $this->build->managed()) {
            throw new BootstrapRedemptionException('runtime_not_hosted', 404);
        }

        $tenantId = $this->build->tenantId();
        $releaseId = $this->build->releaseId();
        $allianceId = $this->configuredAllianceId();

        if ($tenantId === null || ! $this->build->hasConfiguredReleaseId()) {
            throw new BootstrapRedemptionException('configuration_unavailable', 503);
        }

        $claims = $this->introspector->introspect($tokenHash);
        $this->assertClaimsMatchRuntime($claims, $tenantId, $releaseId, $allianceId);

        try {
            return DB::transaction(
                fn (): BootstrapRedemptionResult => $this->redeemTransactionally(
                    $tokenHash,
                    $identity,
                    $claims,
                ),
                3,
            );
        } catch (UniqueConstraintViolationException) {
            throw new BootstrapRedemptionException('bootstrap_already_redeemed', 409);
        }
    }

    private function redeemTransactionally(
        string $tokenHash,
        BootstrapLocalIdentity $identity,
        BootstrapClaims $claims,
    ): BootstrapRedemptionResult {
        if ($claims->expiresAt->getTimestamp() <= now()->getTimestamp()) {
            throw new BootstrapRedemptionException('bootstrap_expired', 403);
        }

        $redemption = BootstrapRedemption::query()->create([
            'token_hash' => $tokenHash,
            'tenant_id' => $claims->tenantId,
            'cloud_user_id' => $claims->cloudUserId,
            'action' => $claims->action,
            'release_id' => $claims->releaseId,
            'alliance_id' => $claims->allianceId,
            'nation_id' => $claims->nationId,
            'claims_digest' => $claims->claimsDigest,
            'issued_at' => $claims->issuedAt,
            'expires_at' => $claims->expiresAt,
        ]);

        $nation = Nation::query()
            ->select(['id', 'alliance_id', 'alliance_position'])
            ->find($claims->nationId);

        if ($nation === null
            || (int) $nation->alliance_id !== $claims->allianceId
            || $nation->alliance_position !== 'LEADER') {
            throw new BootstrapRedemptionException('leader_authority_unavailable', 403);
        }

        $adminRole = Role::query()
            ->where('name', self::ADMIN_ROLE)
            ->lockForUpdate()
            ->first();

        if ($adminRole === null || ! $adminRole->protected) {
            throw new BootstrapRedemptionException('admin_role_unavailable', 503);
        }

        $user = $this->matchingUser($identity, $claims->nationId);
        $this->assertNoDifferentAdministrator($user, $adminRole);

        if ($user === null) {
            $this->assertNewIdentityAvailable($identity);
            $user = User::query()->create([
                'name' => $identity->name,
                'email' => $identity->email,
                'password' => Hash::make($identity->password),
                'nation_id' => $claims->nationId,
                'verification_code' => null,
                'verified_at' => now(),
            ]);
            $mode = BootstrapRedemptionMode::Created;
        } else {
            $this->assertExistingIdentityMatches($user, $identity, $claims->nationId);
            $mode = BootstrapRedemptionMode::Linked;
        }

        $user->forceFill([
            'is_admin' => true,
            'disabled' => false,
            'verification_code' => null,
            'verified_at' => $user->verified_at ?? now(),
        ])->save();
        $user->roles()->syncWithoutDetaching([$adminRole->getKey()]);

        $redeemedAt = now();
        $redemption->forceFill([
            'local_user_id' => $user->getKey(),
            'mode' => $mode,
            'redeemed_at' => $redeemedAt,
        ])->save();

        TenantCallbackDelivery::query()->create([
            'callback_id' => (string) Str::ulid(),
            'tenant_id' => $claims->tenantId,
            'event_type' => TenantCallbackType::BootstrapRedeemed,
            'subject_key' => hash('sha256', 'bootstrap-redemption:'.$redemption->getKey()),
            'release_id' => $claims->releaseId,
            'payload' => [
                'bootstrap_redemption_id' => (int) $redemption->getKey(),
                'cloud_user_id' => $claims->cloudUserId,
                'local_user_id' => (int) $user->getKey(),
                'mode' => $mode->value,
                'nation_id' => $claims->nationId,
            ],
            'status' => TenantCallbackStatus::Pending,
            'attempt_count' => 0,
            'occurred_at' => $redeemedAt,
        ]);

        $this->audit->record(
            category: 'authentication',
            action: 'bootstrap.initial_admin_redeemed',
            subject: $user,
            context: [
                'request' => [
                    'channel' => 'tenant-control-bootstrap',
                ],
                'bootstrap_redemption_id' => (int) $redemption->getKey(),
                'tenant_id' => $claims->tenantId,
                'release_id' => $claims->releaseId,
                'nation_id' => $claims->nationId,
                'local_user_id' => (int) $user->getKey(),
                'mode' => $mode->value,
            ],
            message: 'The managed tenant initial administrator was established.',
            actorOverride: [
                'type' => 'cloud_user',
                'name' => $claims->cloudUserId,
            ],
        );

        return new BootstrapRedemptionResult(
            redemptionId: (int) $redemption->getKey(),
            localUserId: (int) $user->getKey(),
            mode: $mode,
        );
    }

    private function assertClaimsMatchRuntime(
        BootstrapClaims $claims,
        string $tenantId,
        string $releaseId,
        int $allianceId,
    ): void {
        if ($claims->tenantId !== $tenantId
            || $claims->action !== TenantBootstrapAction::InitialAdmin
            || $claims->releaseId !== $releaseId
            || $claims->allianceId !== $allianceId) {
            throw new BootstrapRedemptionException('claims_do_not_match_runtime', 403);
        }
    }

    private function configuredAllianceId(): int
    {
        $configured = config('services.pw.alliance_id');

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        if (is_string($configured)
            && preg_match('/\A[1-9][0-9]{0,19}\z/D', $configured) === 1
            && (int) $configured > 0) {
            return (int) $configured;
        }

        throw new BootstrapRedemptionException('configuration_unavailable', 503);
    }

    private function matchingUser(
        BootstrapLocalIdentity $identity,
        int $nationId,
    ): ?User {
        $canonicalName = Str::lower($identity->name);
        $canonicalEmail = Str::lower($identity->email);
        $matches = User::query()
            ->without('roles')
            ->where(function (Builder $query) use ($nationId, $canonicalName, $canonicalEmail): void {
                $query
                    ->where('nation_id', $nationId)
                    ->orWhere('name_canonical', $canonicalName)
                    ->orWhereRaw('LOWER(email) = ?', [$canonicalEmail]);
            })
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new BootstrapRedemptionException('identity_conflict', 409);
        }

        return $matches->first();
    }

    private function assertExistingIdentityMatches(
        User $user,
        BootstrapLocalIdentity $identity,
        int $nationId,
    ): void {
        if ((int) $user->nation_id !== $nationId
            || ! hash_equals(Str::lower($user->name), Str::lower($identity->name))
            || ! hash_equals(Str::lower($user->email), Str::lower($identity->email))
            || $user->disabled
            || ! Hash::check($identity->password, $user->password)) {
            throw new BootstrapRedemptionException('identity_conflict', 409);
        }
    }

    private function assertNewIdentityAvailable(BootstrapLocalIdentity $identity): void
    {
        $validator = Validator::make([
            'name' => $identity->name,
            'email' => $identity->email,
        ], [
            'name' => [new UniqueCanonicalUsername],
            'email' => [Rule::unique(User::class)],
        ]);

        if ($validator->fails()) {
            throw new BootstrapRedemptionException('identity_conflict', 409);
        }
    }

    private function assertNoDifferentAdministrator(?User $candidate, Role $adminRole): void
    {
        $candidateId = $candidate?->getKey();
        $flaggedAdminIds = User::query()
            ->without('roles')
            ->where('is_admin', true)
            ->lockForUpdate()
            ->pluck('id');
        $roleAdminIds = DB::table('role_user')
            ->where('role_id', $adminRole->getKey())
            ->lockForUpdate()
            ->pluck('user_id');
        $differentAdministratorExists = $flaggedAdminIds
            ->merge($roleAdminIds)
            ->unique()
            ->contains(fn (mixed $userId): bool => (int) $userId !== (int) $candidateId);

        if ($differentAdministratorExists) {
            throw new BootstrapRedemptionException('administrator_already_exists', 409);
        }
    }
}
