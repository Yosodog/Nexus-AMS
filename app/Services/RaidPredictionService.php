<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Raids\RaidWarContext;
use App\Enums\AlliancePositionEnum;
use App\Enums\WarTypeEnum;
use App\Events\WarDeclared;
use App\Models\RaidAllianceProfile;
use App\Models\RaidFinderImpression;
use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\RaidTargetProfile;
use App\Models\War;
use App\Services\Economy\MarketValuationService;
use App\Services\Raids\RaidActivity;
use App\Services\Raids\RaidAttackerFactory;
use App\Services\Raids\RaidTargetClaimService;
use App\Services\Raids\RaidValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Freezes the exact finder valuation a member saw when they declared a raid.
 *
 * Capture runs in the declaration transaction; a unique war_id and a locked
 * existence check keep retries idempotent and the stored prediction immutable.
 */
final class RaidPredictionService
{
    /** @var list<string> */
    private const TARGET_SNAPSHOT_COLUMNS = [
        'nation_id', 'nation_name', 'leader_name', 'alliance_id', 'alliance_position', 'score', 'num_cities',
        'color', 'beige_turns', 'vacation_mode_turns', 'war_policy', 'soldiers', 'tanks', 'aircraft', 'ships',
        'missiles', 'nukes', 'highest_city_population', 'highest_city_infra', 'avg_infra', 'defensive_wars',
        'offensive_wars', 'baseline_kind', 'baseline_attack_id', 'economy_hash', 'retention_observed',
        'retention_samples',
    ];

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly RaidAttackerFactory $attackers,
        private readonly RaidValuationService $valuation,
        private readonly MarketValuationService $prices,
        private readonly RaidTargetClaimService $claims,
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
            $prediction = $war->getConnection()->transaction(function () use (
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
                    ...$this->valuationColumns($attackerId, $targetId, $warId, $declaredAt),
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

        if ($prediction->wasRecentlyCreated) {
            $this->claims->markDeclared($attackerId, $targetId);
        }

        return $prediction;
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
     * Evaluate the declaration with the finder's valuation model.
     *
     * Competing attackers use the same rule as the finder: the larger of the known
     * active wars against the target and the target's defensive war count.
     *
     * @return array<string, mixed>
     */
    private function valuationColumns(int $attackerId, int $targetId, int $warId, CarbonImmutable $declaredAt): array
    {
        $target = RaidTargetProfile::query()->find($targetId);

        if ($target === null || $target->computed_at === null) {
            return $this->incomplete('Target intelligence is unavailable.');
        }

        try {
            $attacker = $this->attackers->forNation($attackerId);
            $alliance = $target->alliance_id > 0 ? RaidAllianceProfile::query()->find($target->alliance_id) : null;
            $knownAttackers = War::query()
                ->active()
                ->where('def_id', $targetId)
                ->where('id', '!=', $warId)
                ->where('att_id', '!=', $attackerId)
                ->count();
            $competingAttackers = max($knownAttackers, (int) $target->defensive_wars);
            $context = new RaidWarContext(
                $competingAttackers,
                (float) ($alliance?->counter_rate ?? config('raids.valuation.default_counter_rate')),
                $warId,
            );
            $prices = $this->prices->current();
            $valuation = $this->valuation->evaluate($attacker, $target, $alliance, $context, $prices, $declaredAt);
        } catch (Throwable $exception) {
            Log::warning('Raid prediction valuation failed.', [
                'attacker_nation_id' => $attackerId,
                'target_nation_id' => $targetId,
                'war_id' => $warId,
                'exception_class' => $exception::class,
            ]);

            return $this->incomplete('Raid valuation failed.');
        }

        $impression = RaidFinderImpression::query()
            ->where('attacker_nation_id', $attackerId)
            ->where('target_nation_id', $targetId)
            ->where('shown_at', '>=', $declaredAt->subHours((int) config('raids.finder.impression_link_hours')))
            ->first();

        return [
            'observed_at' => $target->baseline_at,
            'capture_status' => RaidPrediction::CAPTURE_READY,
            'capture_reason' => null,
            'model_version' => RaidValuationService::MODEL_VERSION,
            'attacker_snapshot' => $attacker->toArray(),
            'target_snapshot' => $target->only(self::TARGET_SNAPSHOT_COLUMNS) + [
                'baseline_at' => $target->baseline_at?->toIso8601String(),
                'baseline' => $target->baselineResources(),
                'daily_net' => $target->dailyNet(),
                'last_active' => $target->last_active?->toIso8601String(),
                'activity_bucket' => RaidActivity::bucket($target->last_active, $declaredAt),
                'stockpile' => $valuation->stockpile,
            ],
            'price_snapshot' => [
                'liquidation' => $prices->liquidationPricesWithMoney(),
                'acquisition' => ['money' => 1.0] + $prices->acquisitionPrices,
                'snapshot_id' => $prices->snapshotId,
                'calculated_at' => $prices->calculatedAt?->toIso8601String(),
            ],
            'context_snapshot' => $context->toArray() + [
                'plan' => $valuation->plan,
                'competing_attackers' => $competingAttackers,
                'assumptions' => $valuation->assumptions,
            ],
            'expected_net' => $valuation->expectedNet,
            'expected_net_low' => $valuation->expectedNetLow,
            'expected_net_high' => $valuation->expectedNetHigh,
            'gross_loot' => $valuation->grossLoot,
            'duration_hours' => $valuation->durationHours,
            'win_probability' => $valuation->winProbability,
            'victory_probability' => $valuation->victoryProbability,
            'expected_attacks' => $valuation->expectedAttacks,
            'confidence' => $valuation->confidence,
            'components' => $valuation->components,
            'loot_resources' => $valuation->lootResources,
            'cost_resources' => $valuation->costResources,
            'finder_rank' => $impression?->rank,
            'finder_expected_net' => $impression?->expected_net,
            'finder_shown_at' => $impression?->shown_at,
        ];
    }

    /**
     * @return array{capture_status: string, capture_reason: string}
     */
    private function incomplete(string $reason): array
    {
        return [
            'capture_status' => RaidPrediction::CAPTURE_INCOMPLETE,
            'capture_reason' => $reason,
        ];
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
