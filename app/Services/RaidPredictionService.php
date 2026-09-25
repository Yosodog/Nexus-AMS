<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Enums\WarTypeEnum;
use App\Events\WarDeclared;
use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns the durable declaration-time raid prediction.
 */
final class RaidPredictionService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly ?RuntimeCapabilities $runtimeCapabilities = null,
    ) {}

    /**
     * Capture a member's qualifying Raid declaration.
     *
     * The event is emitted in the declaration-receipt transaction. A unique
     * war_id and the existing row check make retries immutable and idempotent.
     */
    public function captureDeclaration(
        WarDeclared $event,
        ?CarbonImmutable $capturedAt = null,
    ): ?RaidPrediction {
        $war = War::query()->find($event->warId);

        if ($war === null) {
            Log::warning('Raid declaration prediction skipped because the war row is unavailable.', [
                'war_id' => $event->warId,
            ]);

            return null;
        }

        return $this->captureWar($war, $capturedAt, $event);
    }

    /**
     * Capture a qualifying Raid from a world war row.
     *
     * @param  WarDeclared|null  $event  Declaration-time alliance evidence.
     */
    public function captureWar(
        War $war,
        ?CarbonImmutable $capturedAt = null,
        ?WarDeclared $event = null,
    ): ?RaidPrediction {
        if (! $this->capabilities()->writesTenantPrivate()) {
            return null;
        }

        if (! $this->qualifies($war, $event)) {
            return null;
        }

        $capturedAt ??= CarbonImmutable::now();
        $declaredAt = $this->warDate($war, $capturedAt);
        $attackerId = (int) $war->getAttribute('att_id');
        $targetId = (int) $war->getAttribute('def_id');
        $warId = (int) $war->getKey();
        $attackerAllianceId = $event?->attackerAllianceId
            ?? $this->nullableInteger($war->getAttribute('att_alliance_id'));
        $attackerPosition = $event?->attackerAlliancePosition
            ?? $this->nullableString($war->getAttribute('att_alliance_position'));

        try {
            return $war->getConnection()->transaction(function () use (
                $capturedAt,
                $declaredAt,
                $attackerId,
                $targetId,
                $warId,
                $attackerAllianceId,
                $attackerPosition,
            ): RaidPrediction {
                $existing = RaidPrediction::query()
                    ->where('war_id', $warId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $prediction = RaidPrediction::query()->create([
                    'war_id' => $warId,
                    'attacker_nation_id' => $attackerId,
                    'target_nation_id' => $targetId,
                    'attacker_alliance_id' => $attackerAllianceId,
                    'attacker_alliance_position' => $attackerPosition,
                    'declared_at' => $declaredAt,
                    'captured_at' => $capturedAt,
                    'capture_status' => RaidPrediction::CAPTURE_INCOMPLETE,
                    'capture_reason' => 'Target intelligence is unavailable.',
                    'evaluation_status' => RaidPrediction::EVALUATION_FAILED,
                ]);

                $this->attachUnlinkedAttacks($prediction);

                return $prediction;
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = RaidPrediction::query()->where('war_id', $warId)->lockForUpdate()->first();
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * Return whether this war was a member-declared Raid at declaration time.
     */
    public function qualifies(War $war, ?WarDeclared $event = null): bool
    {
        if (strtoupper((string) $war->getAttribute('war_type')) !== WarTypeEnum::RAID->value) {
            return false;
        }

        $attackerAllianceId = $event?->attackerAllianceId
            ?? $this->nullableInteger($war->getAttribute('att_alliance_id'));
        $attackerPosition = strtoupper((string) ($event?->attackerAlliancePosition
            ?? $war->getAttribute('att_alliance_position')
            ?? ''));

        return $this->membershipService->contains($attackerAllianceId)
            && $attackerPosition !== AlliancePositionEnum::APPLICANT->value;
    }

    private function attachUnlinkedAttacks(RaidPrediction $prediction): void
    {
        RaidOutcomeAttack::query()
            ->where('war_id', (int) $prediction->war_id)
            ->whereNull('raid_prediction_id')
            ->update(['raid_prediction_id' => $prediction->id]);
    }

    private function warDate(War $war, CarbonImmutable $fallback): CarbonImmutable
    {
        $value = $war->getAttribute('date');

        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities ?? app(RuntimeCapabilities::class);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
