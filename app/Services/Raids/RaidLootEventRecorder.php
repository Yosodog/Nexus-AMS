<?php

namespace App\Services\Raids;

use App\Models\Alliance;
use App\Models\Nation;
use App\Models\RaidAllianceProfile;
use App\Models\RaidLootEvent;
use App\Models\RaidTargetProfile;
use App\Models\War;
use App\Services\ApiDateNormalizer;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use App\Services\RaidStockpileEstimator;
use App\Services\WarSimulator\Support\RaidLootFormula;
use App\Services\WarSimulator\Support\WarSimModifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every victory and alliance-loot attack in the world as raid evidence.
 *
 * A victory is also a backtest: the loser's profile prediction immediately before
 * the loot arrived is stored beside the stockpile the loot revealed.
 */
final class RaidLootEventRecorder
{
    public function __construct(
        private RaidLootFraction $fractions,
        private RaidStockpileEstimator $estimator,
        private MarketValuationService $valuation,
        private RaidProfileDirtyMarker $dirty,
    ) {}

    /**
     * @param  array<string, mixed>  $attack  raw subscription attack payload
     */
    public function record(array $attack): ?RaidLootEvent
    {
        $kind = match (strtoupper((string) ($attack['type'] ?? ''))) {
            'VICTORY' => RaidLootEvent::KIND_VICTORY,
            'ALLIANCELOOT' => RaidLootEvent::KIND_ALLIANCE_LOOT,
            default => null,
        };
        $attackId = (int) ($attack['id'] ?? 0);

        if ($kind === null || $attackId <= 0) {
            return null;
        }

        $warId = (int) ($attack['war_id'] ?? 0);
        $attackerId = (int) ($attack['att_id'] ?? 0);
        $defenderId = (int) ($attack['def_id'] ?? 0);
        $war = War::query()->find($warId);
        $winnerId = (int) ($attack['victor'] ?? 0) ?: $attackerId;
        $loserId = $winnerId === $attackerId ? $defenderId : $attackerId;
        $nations = Nation::query()->whereIn('id', [$winnerId, $loserId])->get()->keyBy('id');
        $winner = $nations->get($winnerId);
        $loser = $nations->get($loserId);

        // World `wars` only stores wars that involve a member alliance, so non-member victories
        // fall back to the loser's current alliance and an ORDINARY war type.
        // TODO: Persist the war type of non-member wars (for example a slim world index keyed by
        // war id) if the estimator backtest shows fraction error on `modifiers`-sourced events.
        $loserAllianceId = $war !== null
            ? ((int) ($loserId === (int) $war->def_id ? $war->def_alliance_id : $war->att_alliance_id) ?: null)
            : ((int) $loser?->alliance_id ?: null);
        $warType = strtoupper((string) ($war?->war_type ?? 'ORDINARY'));
        $occurredAt = $this->occurredAt($attack['date'] ?? null);
        $looted = $this->looted($attack);

        [$fraction, $fractionSource] = $kind === RaidLootEvent::KIND_VICTORY
            ? $this->fraction($attack['loot_info'] ?? null, $warType, $winner, $loser)
            : [null, 'default'];

        $lootColumns = $looted + [
            'loot_fraction' => $fraction,
            'fraction_source' => $fractionSource,
        ];
        $event = RaidLootEvent::query()->find($attackId);

        if ($event !== null) {
            $event->fill($lootColumns);

            if ($event->isDirty()) {
                $event->save();
            }
        } else {
            $event = RaidLootEvent::query()->create([
                'id' => $attackId,
                'war_id' => $warId,
                'kind' => $kind,
                'occurred_at' => $occurredAt,
                'winner_nation_id' => $winnerId,
                'loser_nation_id' => $loserId,
                'loser_alliance_id' => $loserAllianceId,
                'war_type' => $warType,
                ...$lootColumns,
                ...($kind === RaidLootEvent::KIND_VICTORY && $fraction > 0
                    ? $this->backtest($loserId, $looted, $fraction, $occurredAt)
                    : []),
            ]);
        }

        $this->dirty->mark([$loserId, $winnerId]);

        if ($kind === RaidLootEvent::KIND_ALLIANCE_LOOT && $loserAllianceId !== null) {
            $this->updateBankEstimate($event, $loserAllianceId, $warType, $winner, $loser, $looted);
        }

        return $event;
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function fraction(mixed $lootInfo, string $warType, ?Nation $winner, ?Nation $loser): array
    {
        $reported = $this->fractions->fromLootInfo(is_string($lootInfo) ? $lootInfo : null);

        if ($reported !== null) {
            return [$reported, 'report'];
        }

        if ($winner !== null && $loser !== null) {
            $projects = $winner->projects;

            return [
                $this->fractions->fromModifiers(
                    $warType,
                    $winner->war_policy,
                    $loser->war_policy,
                    (bool) ($projects['pirate_economy'] ?? false),
                    (bool) ($projects['advanced_pirate_economy'] ?? false),
                ),
                'modifiers',
            ];
        }

        return [RaidLootFormula::DEFAULT_VICTORY_LOOT_FRACTION, 'default'];
    }

    /**
     * @param  array<string, float>  $looted
     * @return array<string, mixed>
     */
    private function backtest(int $loserId, array $looted, float $fraction, CarbonImmutable $occurredAt): array
    {
        $profile = RaidTargetProfile::query()->find($loserId);

        if ($profile === null || $profile->computed_at === null) {
            return [];
        }

        try {
            $predicted = $this->estimator->project($profile, $occurredAt)['resources'];
            $revealed = array_map(fn (float $amount): float => $amount / $fraction, $looted);
            $prices = $this->valuation->current();

            return [
                'predicted_resources' => RaidResources::rounded($predicted),
                'predicted_value' => RaidResources::liquidationValue($predicted, $prices),
                'revealed_value' => RaidResources::liquidationValue($revealed, $prices),
                'prediction_evidence_kind' => $profile->baseline_kind,
                'prediction_age_hours' => round(max(0, $occurredAt->getTimestamp() - $profile->baseline_at->getTimestamp()) / 3600, 2),
                'prediction_activity_bucket' => RaidActivity::bucket($profile->last_active, $occurredAt),
            ];
        } catch (Throwable $exception) {
            Log::warning('Raid loot backtest could not be computed.', [
                'loser_nation_id' => $loserId,
                'exception_class' => $exception::class,
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, float>  $looted
     */
    private function updateBankEstimate(
        RaidLootEvent $event,
        int $allianceId,
        string $warType,
        ?Nation $winner,
        ?Nation $loser,
        array $looted,
    ): void {
        $alliance = Alliance::query()->find($allianceId, ['id', 'score']);
        $profile = RaidAllianceProfile::query()->find($allianceId);

        if ($winner === null || $alliance === null) {
            return;
        }

        if ($profile?->bank_evidence_at !== null && $profile->bank_evidence_at->greaterThan($event->occurred_at)) {
            return;
        }

        $projects = $winner->projects;
        $bankMultiplier = WarSimModifiers::forLoot(
            $warType,
            (string) $winner->war_policy,
            (string) $loser?->war_policy,
            (bool) ($projects['pirate_economy'] ?? false),
            (bool) ($projects['advanced_pirate_economy'] ?? false),
        )->bankLootMultiplier();
        $expected = RaidLootFormula::expectedBankLootFraction((float) $winner->score, (float) $alliance->score, $bankMultiplier);

        if ($expected === null || $expected <= 0) {
            return;
        }

        $bank = collect($looted)->mapWithKeys(fn (float $amount, string $resource): array => [
            'bank_'.$resource => round(max(0.0, $amount / $expected - $amount), 2),
        ]);

        RaidAllianceProfile::query()->updateOrCreate(['alliance_id' => $allianceId], [
            ...$bank->all(),
            'alliance_score' => (float) $alliance->score,
            'bank_evidence_at' => $event->occurred_at,
            'bank_attack_id' => $event->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attack
     * @return array<string, float>
     */
    private function looted(array $attack): array
    {
        return collect(EconomyRules::RESOURCE_KEYS)
            ->mapWithKeys(function (string $resource) use ($attack): array {
                $amount = $resource === 'money'
                    ? ($attack['money_looted'] ?? $attack['money_stolen'] ?? 0)
                    : ($attack[$resource.'_looted'] ?? 0);

                return [$resource => round(max(0.0, is_numeric($amount) ? (float) $amount : 0.0), 2)];
            })
            ->all();
    }

    private function occurredAt(mixed $date): CarbonImmutable
    {
        $normalized = ApiDateNormalizer::normalizeTimestamp($date);

        return $normalized === null ? CarbonImmutable::now() : CarbonImmutable::parse($normalized);
    }
}
