<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AuditImpactConfirmationService
{
    private const TOKEN_LIFETIME_MINUTES = 10;

    public function __construct(private readonly AuditRuleDefinitionService $definitions) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function issue(User $user, AuditTargetType $targetType, array $definition): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => 'audit_rule_impact_confirmation',
            'user_id' => $user->id,
            'target_type' => $targetType->value,
            'definition_fingerprint' => $this->definitions->fingerprint($targetType, $definition),
            'expires_at' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws ValidationException
     */
    public function assertValid(
        ?string $token,
        User $user,
        AuditTargetType $targetType,
        array $definition,
    ): void {
        try {
            $payload = json_decode(Crypt::decryptString((string) $token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $payload = null;
        }

        $valid = is_array($payload)
            && ($payload['purpose'] ?? null) === 'audit_rule_impact_confirmation'
            && (int) ($payload['user_id'] ?? 0) === (int) $user->id
            && ($payload['target_type'] ?? null) === $targetType->value
            && ($payload['definition_fingerprint'] ?? null) === $this->definitions->fingerprint($targetType, $definition)
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;

        if (! $valid) {
            throw ValidationException::withMessages([
                'impact_confirmation_token' => 'Test the current rule and confirm its impact before activating it.',
            ]);
        }
    }
}
