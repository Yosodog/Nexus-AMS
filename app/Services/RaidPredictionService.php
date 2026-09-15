<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlliancePositionEnum;
use App\Enums\WarTypeEnum;
use App\Events\WarDeclared;
use App\Jobs\EvaluateRaidPredictionJob;
use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Owns the durable declaration snapshot and its asynchronous evaluation.
 *
 * Evaluation receives the shared intelligence and simulation services through
 * dependency injection while queued work only consumes the frozen JSON stored
 * on RaidPrediction.
 */
final class RaidPredictionService
{
    public const MODEL_VERSION = 'raid-profit-v1';

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly RaidIntelligenceService $intelligenceService,
        private readonly RaidSimulationService $simulationService,
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

                $snapshot = $this->freezeSnapshot(
                    $attackerId,
                    $targetId,
                    $declaredAt,
                    $warId,
                );
                $canEvaluate = $snapshot['status'] === RaidPrediction::CAPTURE_READY
                    && is_array($snapshot['attacker'])
                    && is_array($snapshot['target'])
                    && is_array($snapshot['stockpile'])
                    && is_array($snapshot['prices'])
                    && is_array($snapshot['context']);

                $prediction = RaidPrediction::query()->create([
                    'war_id' => $warId,
                    'attacker_nation_id' => $attackerId,
                    'target_nation_id' => $targetId,
                    'attacker_alliance_id' => $attackerAllianceId,
                    'attacker_alliance_position' => $attackerPosition,
                    'declared_at' => $declaredAt,
                    'captured_at' => $capturedAt,
                    'observed_at' => $snapshot['observed_at'],
                    'capture_status' => $snapshot['status'],
                    'capture_reason' => $snapshot['reason'],
                    'evaluation_status' => ! $canEvaluate
                        ? RaidPrediction::EVALUATION_FAILED
                        : RaidPrediction::EVALUATION_QUEUED,
                    'model_version' => $snapshot['model_version'],
                    'attacker_snapshot' => $snapshot['attacker'],
                    'target_snapshot' => $snapshot['target'],
                    'stockpile_snapshot' => $snapshot['stockpile'],
                    'price_snapshot' => $snapshot['prices'],
                    'context_snapshot' => $snapshot['context'],
                    'provenance' => $snapshot['provenance'],
                    'frozen_payload' => $snapshot['payload'],
                ]);

                $this->attachUnlinkedAttacks($prediction);

                if ($prediction->evaluation_status === RaidPrediction::EVALUATION_QUEUED) {
                    EvaluateRaidPredictionJob::dispatch($prediction->id)->afterCommit();
                }

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
     * Evaluate a stored prediction. No world rows or live services are read.
     */
    public function evaluate(int|RaidPrediction $prediction): RaidPrediction
    {
        $prediction = $prediction instanceof RaidPrediction
            ? $prediction
            : RaidPrediction::query()->findOrFail($prediction);

        if (! $this->capabilities()->writesTenantPrivate()) {
            return $prediction;
        }

        if ($prediction->isEvaluated()) {
            return $prediction;
        }

        if ($prediction->model_version !== self::MODEL_VERSION) {
            $prediction->forceFill([
                'evaluation_status' => RaidPrediction::EVALUATION_FAILED,
                'outcome_metadata' => array_merge(
                    is_array($prediction->outcome_metadata) ? $prediction->outcome_metadata : [],
                    [
                        'evaluation_error' => 'Unsupported raid prediction model version.',
                        'unsupported_model_version' => $prediction->model_version,
                        'supported_model_version' => self::MODEL_VERSION,
                    ],
                ),
            ])->saveQuietly();

            return $prediction->refresh();
        }

        if ($prediction->capture_status !== RaidPrediction::CAPTURE_READY
            || ! is_array($prediction->attacker_snapshot)
            || ! is_array($prediction->target_snapshot)
            || ! is_array($prediction->stockpile_snapshot)
            || ! is_array($prediction->price_snapshot)
            || ! is_array($prediction->context_snapshot)) {
            return $prediction;
        }

        $prediction->forceFill([
            'evaluation_status' => RaidPrediction::EVALUATION_RUNNING,
        ])->saveQuietly();

        try {
            $result = $this->simulationService->evaluate(
                $prediction->attacker_snapshot,
                $prediction->target_snapshot,
                $prediction->stockpile_snapshot,
                $prediction->price_snapshot,
                $prediction->context_snapshot,
            );

            if (! is_array($result)) {
                throw new LogicException('Raid simulation returned an invalid result.');
            }

            $prediction->forceFill([
                'evaluation_status' => RaidPrediction::EVALUATION_COMPLETE,
                'expected_net' => $this->nullableFloat($this->resultValue($result, 'expected_net')),
                'gross_loot' => $this->nullableFloat($this->resultValue($result, 'gross_loot')),
                'conservative_net' => $this->nullableFloat($this->resultValue($result, 'conservative_net')),
                'duration_hours' => $this->nullableFloat($this->resultValue($result, 'duration_hours')),
                'components' => $this->resultArray($result, 'components'),
                'loot_resources' => $this->resultArray($result, 'loot_resources'),
                'cost_resources' => $this->resultArray($result, 'cost_resources'),
                'scenarios' => $this->resultArray($result, 'scenarios'),
                'simulation_payload' => $result,
                'evaluated_at' => CarbonImmutable::now(),
            ])->saveQuietly();

            return $prediction->refresh();
        } catch (Throwable $exception) {
            $prediction->forceFill([
                'evaluation_status' => RaidPrediction::EVALUATION_FAILED,
                'outcome_metadata' => array_merge(
                    is_array($prediction->outcome_metadata) ? $prediction->outcome_metadata : [],
                    ['evaluation_error' => 'Raid simulation failed.'],
                ),
            ])->saveQuietly();

            Log::warning('Raid prediction evaluation failed.', [
                'prediction_id' => $prediction->id,
                'war_id' => $prediction->war_id,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
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

    /**
     * @return array{
     *     attacker: array<string, mixed>|null,
     *     target: array<string, mixed>|null,
     *     stockpile: array<string, mixed>|null,
     *     prices: array<string, mixed>|null,
     *     context: array<string, mixed>|null,
     *     observed_at: CarbonImmutable|null,
     *     provenance: array<string, mixed>|null,
     *     payload: array<string, mixed>|null,
     *     status: string,
     *     reason: string|null,
     *     model_version: string|null
     * }
     */
    private function freezeSnapshot(
        int $attackerId,
        int $targetId,
        CarbonImmutable $cutoff,
        int $excludedWarId,
    ): array {
        try {
            $payload = $this->intelligenceService->freeze($attackerId, $targetId, $cutoff, $excludedWarId);

            if (! is_array($payload)) {
                throw new LogicException('Raid intelligence returned an invalid snapshot.');
            }

            $status = $this->normaliseCaptureStatus($payload['status'] ?? null);

            return [
                'attacker' => $this->normaliseArray($payload['attacker'] ?? null),
                'target' => $this->normaliseArray($payload['target'] ?? null),
                'stockpile' => $this->normaliseArray($payload['stockpile'] ?? null),
                'prices' => $this->normaliseArray($payload['prices'] ?? null),
                'context' => $this->normaliseArray($payload['context'] ?? null),
                'observed_at' => $this->normaliseTimestamp($payload['observed_at'] ?? null),
                'provenance' => $this->normaliseArray($payload['provenance'] ?? null),
                'payload' => $payload,
                'status' => $status,
                'reason' => $this->nullableString($payload['reason'] ?? null)
                    ?? ($status === RaidPrediction::CAPTURE_CLEAN
                        ? null
                        : 'Raid intelligence snapshot is degraded or incomplete.'),
                // The model version belongs to this application’s persisted
                // prediction contract. The intelligence context can contain a
                // separate simulator/configuration version, but it must not
                // silently select a different evaluator after a deployment.
                'model_version' => self::MODEL_VERSION,
            ];
        } catch (Throwable $exception) {
            Log::warning('Raid declaration prediction captured without a clean intelligence snapshot.', [
                'attacker_nation_id' => $attackerId,
                'target_nation_id' => $targetId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'attacker' => null,
                'target' => null,
                'stockpile' => null,
                'prices' => null,
                'context' => null,
                'observed_at' => null,
                'provenance' => [
                    'status' => RaidPrediction::CAPTURE_INCOMPLETE,
                    'excluded_inputs' => ['declaration_war_attacks'],
                ],
                'payload' => null,
                'status' => RaidPrediction::CAPTURE_INCOMPLETE,
                'reason' => 'Clean raid intelligence snapshot was unavailable at declaration.',
                'model_version' => null,
            ];
        }
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

    private function normaliseCaptureStatus(mixed $status): string
    {
        $status = strtolower((string) $status);

        return match ($status) {
            RaidPrediction::CAPTURE_CLEAN => RaidPrediction::CAPTURE_CLEAN,
            RaidPrediction::CAPTURE_DEGRADED => RaidPrediction::CAPTURE_DEGRADED,
            default => RaidPrediction::CAPTURE_INCOMPLETE,
        };
    }

    /** @return array<string, mixed>|null */
    private function normaliseArray(mixed $value): ?array
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        return is_array($value) ? $value : null;
    }

    private function normaliseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function resultValue(array $result, string $key): mixed
    {
        if (array_key_exists($key, $result)) {
            return $result[$key];
        }

        return data_get($result, 'metrics.'.$key);
    }

    /** @return array<string, mixed>|null */
    private function resultArray(array $result, string $key): ?array
    {
        $value = $this->resultValue($result, $key);

        return is_array($value) ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
