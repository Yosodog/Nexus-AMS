<?php

namespace App\Services\Alerts;

use App\Models\AlertOccurrence;
use App\Models\Nation;
use App\Services\Economy\EconomyRules;
use App\Services\NationProfitabilityService;
use App\Services\PWHelperService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

class ResourceShortfallService
{
    public const EVENT_KEY = 'nation.resource_shortfall';

    public const MAXIMUM_DATA_AGE_HOURS = 3;

    public const TARGET_TURNS = 12;

    public function __construct(private readonly NationProfitabilityService $profitability) {}

    /**
     * @return array{
     *     calculated_at: CarbonInterface,
     *     resource_snapshot_at: CarbonInterface,
     *     snapshot_fingerprint: string,
     *     resources: array<string, string>,
     *     lines: list<array{resource:string,on_hand:string,next_turn_requirement:string,withdrawal_requirement:string}>
     * }|null
     */
    public function project(Nation $nation): ?array
    {
        $nation->loadMissing('resources');
        $snapshot = $nation->resources;

        if ($snapshot === null
            || $snapshot->updated_at === null
            || $snapshot->updated_at->lt(now()->subHours(self::MAXIMUM_DATA_AGE_HOURS))) {
            return null;
        }

        $projection = $this->profitability->getDailyTradeResourceShortfallProjection(
            $nation,
            self::MAXIMUM_DATA_AGE_HOURS,
        );
        if ($projection === null) {
            return null;
        }

        $withdrawalResources = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => '0.00'])
            ->all();
        $lines = [];

        foreach (EconomyRules::TRADE_RESOURCES as $resource) {
            $dailyShortfall = BigDecimal::of((string) max(
                0.0,
                (float) ($projection['shortfalls_per_day'][$resource] ?? 0.0),
            ));
            $nextTurnRequirement = $dailyShortfall
                ->dividedBy(EconomyRules::TURNS_PER_DAY, 8, RoundingMode::Ceiling)
                ->toScale(2, RoundingMode::Ceiling);
            $onHand = BigDecimal::of((string) ($snapshot->{$resource} ?? 0))->toScale(2, RoundingMode::Down);

            if ($nextTurnRequirement->isZero() || $onHand->isGreaterThanOrEqualTo($nextTurnRequirement)) {
                continue;
            }

            $withdrawalRequirement = $dailyShortfall
                ->minus($onHand)
                ->toScale(2, RoundingMode::Ceiling);
            if ($withdrawalRequirement->isLessThanOrEqualTo(0)) {
                continue;
            }

            $withdrawalResources[$resource] = (string) $withdrawalRequirement;
            $lines[] = [
                'resource' => $resource,
                'on_hand' => (string) $onHand,
                'next_turn_requirement' => (string) $nextTurnRequirement,
                'withdrawal_requirement' => (string) $withdrawalRequirement,
            ];
        }

        if ($lines === []) {
            return null;
        }

        $fingerprintData = [
            'nation_id' => (int) $nation->id,
            'calculated_at' => $projection['calculated_at']->utc()->toISOString(),
            'resource_snapshot_at' => $snapshot->updated_at->utc()->toISOString(),
            'resources' => $withdrawalResources,
        ];

        return [
            'calculated_at' => $projection['calculated_at'],
            'resource_snapshot_at' => $snapshot->updated_at,
            'snapshot_fingerprint' => hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR)),
            'resources' => $withdrawalResources,
            'lines' => $lines,
        ];
    }

    public function occurrenceIsStillRelevant(AlertOccurrence $occurrence): bool
    {
        if ($occurrence->event_key !== self::EVENT_KEY || $occurrence->audience_user_id === null) {
            return false;
        }

        $nation = Nation::query()->find($occurrence->subject_id);

        if ($nation === null) {
            return false;
        }

        $projection = $this->project($nation);

        return $projection !== null
            && is_string($occurrence->source_version)
            && hash_equals($occurrence->source_version, $projection['snapshot_fingerprint']);
    }
}
