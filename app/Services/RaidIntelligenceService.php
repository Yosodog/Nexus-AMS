<?php

namespace App\Services;

use App\DataTransferObjects\MarketPriceSet;
use App\DataTransferObjects\WarSim\WarSimRequestData;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Models\RadiationSnapshot;
use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Models\WarAttack;
use App\Services\Economy\EconomyCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\Economy\MarketValuationService;
use App\Services\WarSimulator\WarSimulationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RaidIntelligenceService
{
    public function __construct(
        private EconomyCalculator $economy,
        private MarketValuationService $valuation,
        private RaidStockpileEstimator $stockpiles,
        private ?RuntimeCapabilities $runtimeCapabilities = null,
        private ?AllianceMembershipService $membershipService = null,
    ) {}

    /** @return array<string, mixed> */
    public function freeze(int $attackerId, int $targetId, CarbonImmutable $cutoff, ?int $excludedWarId = null): array
    {
        $attacker = $this->nationAt($attackerId, $cutoff, $excludedWarId);
        $target = $this->nationAt($targetId, $cutoff, $excludedWarId);
        $base = [
            'attacker' => $attacker, 'target' => $target, 'observed_at' => $target['observed_at'] ?? null,
            'captured_at' => $cutoff->toIso8601String(), 'excluded_war_id' => $excludedWarId,
            'status' => 'incomplete', 'prices' => [], 'stockpile' => [], 'context' => [],
            'provenance' => ['war_ids' => array_values(array_unique(array_merge($attacker['provenance_war_ids'] ?? [], $target['provenance_war_ids'] ?? [])))],
        ];
        if ($attacker === [] || $target === []) {
            return $base + ['reason' => 'Clean nation intelligence was unavailable at capture.'];
        }
        try {
            $prices = $this->pricesAt($cutoff, $excludedWarId);
        } catch (Throwable $exception) {
            return $base + ['reason' => 'Usable market prices are unavailable.'];
        }
        $attacker = $this->withEconomy($attacker, $prices, $cutoff, true, $excludedWarId);
        $target = $this->withEconomy($target, $prices, $cutoff, true, $excludedWarId);
        $target = Arr::except($target, [
            ...EconomyRules::RESOURCE_KEYS,
            'credits', 'discord', 'discord_id', 'tax_id', 'spies', 'spies_today',
        ]);
        $attackerPrivateResources = $this->attackerPrivateResources($attackerId, $attacker, $cutoff, $excludedWarId);
        if ($attackerPrivateResources !== null) {
            $attacker['resources'] = Arr::only($attackerPrivateResources->getAttributes(), EconomyRules::RESOURCE_KEYS);
            $attacker['money'] = $attacker['resources']['money'] ?? null;
            $attacker['resources_updated_at'] = $attackerPrivateResources->updated_at?->toIso8601String();
            $attacker['private_resources_available'] = true;
        } else {
            $attacker['private_resources_available'] = false;
        }
        $attackerPrivateMilitary = $this->attackerPrivateMilitary($attackerId, $attacker, $cutoff, $excludedWarId);
        if ($attackerPrivateMilitary !== null) {
            $military = Arr::only($attackerPrivateMilitary->getAttributes(), [
                'soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies',
            ]);
            $attacker['military'] = $military;
            foreach (array_keys($military) as $unit) {
                $attacker[$unit] = $military[$unit];
            }
            $attacker['military_updated_at'] = $attackerPrivateMilitary->updated_at?->toIso8601String();
            $attacker['private_military_available'] = true;
        } else {
            $attacker['private_military_available'] = false;
        }
        $attacks = RaidAttackObservation::query()
            ->where(fn ($q) => $q->where('att_id', $targetId)->orWhere('def_id', $targetId))
            ->where('observed_at', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->where('occurred_at', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->when($excludedWarId !== null, fn ($q) => $q->where('war_id', '!=', $excludedWarId))
            ->orderBy('occurred_at')->orderBy('id')->get()->pluck('payload')->all();
        $observations = [];
        foreach ($attacks as $attack) {
            $winner = (int) ($attack['victor'] ?? $attack['att_id'] ?? 0);
            if (strtoupper((string) ($attack['type'] ?? '')) !== 'VICTORY' || $winner === $targetId) {
                continue;
            }
            $resources = [];
            foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                $amount = $attack[$resource.'_looted'] ?? null;
                if (! is_numeric($amount) || is_bool($amount) || (float) $amount <= 0) {
                    continue;
                }

                $resources[$resource] = (float) $amount;
            }
            if ($resources === []) {
                continue;
            }
            $date = CarbonImmutable::parse($attack['date']);
            $historicalAttackerId = (int) ($attack['att_id'] ?? 0);
            $defenderId = (int) ($attack['def_id'] ?? 0);
            $attackerSnapshot = $this->nationAt($historicalAttackerId, $date, $excludedWarId);
            $defenderSnapshot = $this->nationAt($defenderId, $date, $excludedWarId);
            $warType = strtoupper((string) ($attack['war_type'] ?? 'RAID'));
            $attackerPolicy = strtoupper((string) ($attackerSnapshot['war_policy'] ?? $attack['attacker_policy'] ?? 'NONE'));
            $defenderPolicy = strtoupper((string) ($defenderSnapshot['war_policy'] ?? $attack['defender_policy'] ?? 'NONE'));
            $winnerIsAttacker = $winner === $historicalAttackerId;
            $winnerPirateEconomy = $winnerIsAttacker
                ? (bool) ($attackerSnapshot['pirate_economy'] ?? $attack['attacker_pirate_economy'] ?? false)
                : (bool) ($defenderSnapshot['pirate_economy'] ?? $attack['defender_pirate_economy'] ?? false);
            $winnerAdvancedPirateEconomy = $winnerIsAttacker
                ? (bool) ($attackerSnapshot['advanced_pirate_economy'] ?? $attack['attacker_advanced_pirate_economy'] ?? false)
                : (bool) ($defenderSnapshot['advanced_pirate_economy'] ?? $attack['defender_advanced_pirate_economy'] ?? false);
            $fraction = app(WarSimulationService::class)->victoryLootFraction(
                WarSimRequestData::fromArray([
                    'context' => [
                        'war_type' => $warType,
                        // The shared formula applies loot modifiers to its
                        // attacker/defender roles, so map the historical
                        // winner onto attacker for a defender victory.
                        'attacker_policy' => $winnerIsAttacker ? $attackerPolicy : $defenderPolicy,
                        'defender_policy' => $winnerIsAttacker ? $defenderPolicy : $attackerPolicy,
                        'attacker_pirate_economy' => $winnerPirateEconomy,
                        'attacker_advanced_pirate_economy' => $winnerAdvancedPirateEconomy,
                    ],
                ]),
            );
            $providedFraction = $attack['loot_fraction'] ?? null;
            if (! $this->isUsableLootFraction($providedFraction)) {
                $providedFraction = $this->extractLootFraction($attack['loot_info'] ?? null);
            }
            $hasExactFraction = $this->isUsableLootFraction($providedFraction);
            $winnerSnapshot = $winnerIsAttacker ? $attackerSnapshot : $defenderSnapshot;
            $loserSnapshot = $winnerIsAttacker ? $defenderSnapshot : $attackerSnapshot;
            $winnerPolicyKey = $winnerIsAttacker ? 'attacker_policy' : 'defender_policy';
            $loserPolicyKey = $winnerIsAttacker ? 'defender_policy' : 'attacker_policy';
            $winnerPirateKey = $winnerIsAttacker ? 'attacker_pirate_economy' : 'defender_pirate_economy';
            $winnerAdvancedPirateKey = $winnerIsAttacker ? 'attacker_advanced_pirate_economy' : 'defender_advanced_pirate_economy';
            $winnerPolicyFromSnapshot = isset($winnerSnapshot['war_policy']) && trim((string) $winnerSnapshot['war_policy']) !== '';
            $loserPolicyFromSnapshot = isset($loserSnapshot['war_policy']) && trim((string) $loserSnapshot['war_policy']) !== '';
            $winnerPolicyFromAttack = isset($attack[$winnerPolicyKey]) && trim((string) $attack[$winnerPolicyKey]) !== '';
            $loserPolicyFromAttack = isset($attack[$loserPolicyKey]) && trim((string) $attack[$loserPolicyKey]) !== '';
            $winnerPirateFromSnapshot = array_key_exists('pirate_economy', $winnerSnapshot) && $winnerSnapshot['pirate_economy'] !== null;
            $winnerAdvancedPirateFromSnapshot = array_key_exists('advanced_pirate_economy', $winnerSnapshot) && $winnerSnapshot['advanced_pirate_economy'] !== null;
            $winnerPirateFromAttack = array_key_exists($winnerPirateKey, $attack) && $attack[$winnerPirateKey] !== null;
            $winnerAdvancedPirateFromAttack = array_key_exists($winnerAdvancedPirateKey, $attack) && $attack[$winnerAdvancedPirateKey] !== null;
            $historicalModifierInputs = [
                'war_type' => [
                    'value' => $warType,
                    'source' => array_key_exists('war_type', $attack) ? 'attack_observation' : 'default',
                ],
                'winner_war_policy' => [
                    'value' => $winnerIsAttacker ? $attackerPolicy : $defenderPolicy,
                    'source' => $winnerPolicyFromSnapshot ? 'historical_snapshot' : ($winnerPolicyFromAttack ? 'attack_observation' : 'default'),
                ],
                'loser_war_policy' => [
                    'value' => $winnerIsAttacker ? $defenderPolicy : $attackerPolicy,
                    'source' => $loserPolicyFromSnapshot ? 'historical_snapshot' : ($loserPolicyFromAttack ? 'attack_observation' : 'default'),
                ],
                'winner_pirate_economy' => [
                    'value' => $winnerPirateEconomy,
                    'source' => $winnerPirateFromSnapshot ? 'historical_snapshot' : ($winnerPirateFromAttack ? 'attack_observation' : 'default'),
                ],
                'winner_advanced_pirate_economy' => [
                    'value' => $winnerAdvancedPirateEconomy,
                    'source' => $winnerAdvancedPirateFromSnapshot ? 'historical_snapshot' : ($winnerAdvancedPirateFromAttack ? 'attack_observation' : 'default'),
                ],
            ];
            $fractionSource = $hasExactFraction
                ? 'loot_report'
                : (collect($historicalModifierInputs)->every(fn (array $input): bool => $input['source'] === 'historical_snapshot' || $input['source'] === 'attack_observation')
                    ? 'historical_inputs'
                    : 'formula_defaults');
            $warId = $this->nullableInteger($attack['war_id'] ?? null);
            $observations[] = [
                'id' => $attack['id'], 'observed_at' => $attack['date'], 'kind' => 'victory',
                'resources' => $resources, 'source_attack_id' => $attack['id'], 'source_war_id' => $warId,
                'provenance_war_ids' => $warId === null ? [] : [$warId], 'modifier_known' => $hasExactFraction,
                'loot_fraction' => $hasExactFraction ? $providedFraction : $fraction, 'war_type' => $warType,
                'fraction_source' => $fractionSource, 'fraction_inputs' => $historicalModifierInputs,
                'loot_info' => $attack['loot_info'] ?? null,
            ];
        }
        $history = RaidNationObservation::query()
            ->where('nation_id', $targetId)
            ->where('valid_from', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->where(function ($query) use ($cutoff): void {
                $query->where('confirmed_through', '>=', $cutoff->subDays((int) config('raids.history_days', 31)))
                    ->orWhere('current_key', 1);
            })
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get();
        $target['production_context'] = [];
        foreach ($history as $snapshot) {
            if (! $this->isClean($snapshot, $excludedWarId)) {
                continue;
            }
            $effectiveAt = $snapshot->valid_from ?? $snapshot->observed_at;
            $context = $this->withEconomy($snapshot->payload + ['observed_at' => $effectiveAt->toIso8601String()], $prices, $effectiveAt, false, $excludedWarId);
            $target['production_context'][] = Arr::only($context, ['observed_at', 'daily_output', 'daily_expenses', 'daily_net', 'vacation_mode_turns', 'production_processes']);
        }
        $stockpile = $this->stockpiles->estimate($target, $observations, $attacks, $cutoff, $excludedWarId);
        $base['attacker'] = $attacker;
        $base['target'] = $target;
        $base['stockpile'] = $stockpile;
        $base['calculation'] = $stockpile['calculation'] ?? [
            'as_of' => $cutoff->toIso8601String(),
            'prices_at' => null,
            'model_version' => $stockpile['model_version'] ?? null,
            'coverage' => ['status' => 'unknown', 'label' => 'Insufficient evidence'],
            'observations' => [],
            'resources' => [],
            'uncertainties' => ['Stockpile calculation evidence was unavailable.'],
        ];
        $base['calculation']['prices_at'] = $prices->calculatedAt?->toIso8601String();
        $base['calculation']['prices'] = [
            'liquidation' => ['money' => 1.0] + $prices->liquidationPrices,
            'acquisition' => ['money' => 1.0] + $prices->acquisitionPrices,
            'snapshot_id' => $prices->snapshotId,
            'basis' => $prices->basis,
        ];
        $base['prices'] = [
            'acquisition' => ['money' => 1.0] + $prices->acquisitionPrices,
            'liquidation' => ['money' => 1.0] + $prices->liquidationPrices,
            'snapshot_id' => $prices->snapshotId, 'calculated_at' => $prices->calculatedAt?->toIso8601String(),
            'basis' => $prices->basis, 'stale' => $prices->stale,
        ];
        $base['context'] = [
            'as_of' => $cutoff->toIso8601String(),
            // The estimator has already advanced balances through the frozen cutoff.
            'hours_since_observation' => 0.0,
            'war_type' => 'RAID', 'seed' => $excludedWarId ?? $targetId,
            'defensive_wars' => $this->defensiveWarsForCapture($target, $excludedWarId),
            'bounties' => $target['bounties'] ?? [],
            'iterations' => (int) config('raids.simulation_iterations', 100),
            'model_version' => (int) config('raids.model_version', 1),
            'attacker_funds_available' => $attackerPrivateResources !== null,
        ];
        $base['provenance']['war_ids'] = array_values(array_unique(array_merge($base['provenance']['war_ids'], $stockpile['provenance_war_ids'] ?? [])));
        $knownResources = array_filter($stockpile['resources'] ?? [], 'is_numeric');
        $base['status'] = $knownResources === [] ? 'incomplete' : 'ready';
        if ($knownResources === []) {
            $base['reason'] = 'Resource balances could not be estimated from clean evidence.';
        }

        return $base;
    }

    private function pricesAt(CarbonImmutable $cutoff, ?int $excludedWarId): MarketPriceSet
    {
        $snapshot = MarketPriceSnapshot::query()
            ->where('calculated_at', '<=', $cutoff)
            ->latest('calculated_at')
            ->latest('id')
            ->first();

        if ($snapshot !== null) {
            try {
                return $this->valuation->current((int) $snapshot->getKey());
            } catch (Throwable $exception) {
                if ($excludedWarId !== null) {
                    throw $exception;
                }

                return $this->valuation->current();
            }
        }

        if ($excludedWarId !== null) {
            throw new RuntimeException('No dated market price snapshot exists before the capture cutoff.');
        }

        return $this->valuation->current();
    }

    /**
     * Return private funds only for an attacker nation that belongs to this
     * tenant. Target snapshots intentionally never consult this relation.
     */
    private function attackerPrivateResources(
        int $attackerId,
        array $attacker,
        CarbonImmutable $cutoff,
        ?int $excludedWarId,
    ): ?NationResources {
        if (! $this->capabilities()->writesTenantPrivate()) {
            return null;
        }

        $allianceId = $this->nullableInteger($attacker['alliance_id'] ?? null)
            ?? $this->nullableInteger(Nation::query()->whereKey($attackerId)->value('alliance_id'));
        if (! $this->membership()->contains($allianceId)) {
            return null;
        }

        $resources = NationResources::query()
            ->where('nation_id', $attackerId)
            ->where('updated_at', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->latest('updated_at')
            ->latest('id')
            ->first();
        if ($resources === null || ! $resources->updated_at instanceof CarbonInterface) {
            return null;
        }

        $updatedAt = CarbonImmutable::instance($resources->updated_at);
        $freshSeconds = max(0, (int) config('raids.fresh_seconds', 300));
        if ($updatedAt->lt($cutoff->subSeconds($freshSeconds))) {
            return null;
        }
        $hasLaterKnownAttack = RaidAttackObservation::query()
            ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
            ->where('occurred_at', '>', $updatedAt)
            ->where('occurred_at', '<=', $cutoff)
            ->exists();
        if (! $hasLaterKnownAttack) {
            $hasLaterKnownAttack = WarAttack::query()
                ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
                ->where('date', '>', $updatedAt)
                ->where('date', '<=', $cutoff)
                ->exists();
        }
        if ($hasLaterKnownAttack) {
            return null;
        }
        if ($excludedWarId !== null && (
            WarAttack::query()->where('war_id', $excludedWarId)->where('date', '<=', $updatedAt)->exists()
            || RaidAttackObservation::query()->where('war_id', $excludedWarId)->where('occurred_at', '<=', $updatedAt)->exists()
        )) {
            return null;
        }

        return $resources;
    }

    /**
     * Return a fresher private military snapshot only when its timestamp is
     * before the capture cutoff and no known attack can have changed it.
     * Public military remains the fallback when private data is stale or
     * unavailable.
     */
    private function attackerPrivateMilitary(
        int $attackerId,
        array $attacker,
        CarbonImmutable $cutoff,
        ?int $excludedWarId,
    ): ?NationMilitary {
        if (! $this->capabilities()->writesTenantPrivate()) {
            return null;
        }

        $allianceId = $this->nullableInteger($attacker['alliance_id'] ?? null)
            ?? $this->nullableInteger(Nation::query()->whereKey($attackerId)->value('alliance_id'));
        if (! $this->membership()->contains($allianceId)) {
            return null;
        }

        $military = NationMilitary::query()
            ->where('nation_id', $attackerId)
            ->where('updated_at', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->latest('updated_at')
            ->latest('id')
            ->first();
        if ($military === null || ! $military->updated_at instanceof CarbonInterface) {
            return null;
        }

        $updatedAt = CarbonImmutable::instance($military->updated_at);
        $publicObservedAt = null;
        if (isset($attacker['observed_at'])) {
            try {
                $publicObservedAt = CarbonImmutable::parse((string) $attacker['observed_at']);
            } catch (Throwable) {
                $publicObservedAt = null;
            }
        }
        if ($publicObservedAt !== null && ! $updatedAt->greaterThan($publicObservedAt)) {
            return null;
        }

        $hasLaterKnownAttack = RaidAttackObservation::query()
            ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
            ->where('occurred_at', '>', $updatedAt)
            ->where('occurred_at', '<=', $cutoff)
            ->exists();
        if (! $hasLaterKnownAttack) {
            $hasLaterKnownAttack = WarAttack::query()
                ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
                ->where('date', '>', $updatedAt)
                ->where('date', '<=', $cutoff)
                ->exists();
        }
        if ($hasLaterKnownAttack) {
            return null;
        }

        if ($excludedWarId !== null && (
            WarAttack::query()
                ->where('war_id', $excludedWarId)
                ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
                ->where('date', '<=', $updatedAt)
                ->exists()
            || RaidAttackObservation::query()
                ->where('war_id', $excludedWarId)
                ->where(fn ($query) => $query->where('att_id', $attackerId)->orWhere('def_id', $attackerId))
                ->where('occurred_at', '<=', $updatedAt)
                ->exists()
        )) {
            return null;
        }

        return $military;
    }

    private function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities ?? app(RuntimeCapabilities::class);
    }

    private function membership(): AllianceMembershipService
    {
        return $this->membershipService ?? app(AllianceMembershipService::class);
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (! is_numeric($value) || is_bool($value) || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    public function nationAt(int $nationId, CarbonImmutable $cutoff, ?int $excludedWarId = null): array
    {
        return $this->nationsAt([$nationId], $cutoff, $excludedWarId)[$nationId] ?? [];
    }

    /**
     * Read the latest clean public observation for several nations with one
     * bounded query. Finder requests use this to avoid one observation query
     * per target before the expensive stockpile freeze.
     *
     * @param  list<int>  $nationIds
     * @return array<int, array<string, mixed>>
     */
    public function nationsAt(array $nationIds, CarbonImmutable $cutoff, ?int $excludedWarId = null): array
    {
        $nationIds = array_values(array_unique(array_filter(
            array_map('intval', $nationIds),
            static fn (int $nationId): bool => $nationId > 0,
        )));

        if ($nationIds === []) {
            return [];
        }

        $snapshots = RaidNationObservation::query()
            ->whereIn('nation_id', $nationIds)
            ->where('valid_from', $excludedWarId === null ? '<=' : '<', $cutoff)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $nationId = (int) $snapshot->nation_id;
            if (isset($latest[$nationId]) || ! $this->isClean($snapshot, $excludedWarId)) {
                continue;
            }

            $latest[$nationId] = $snapshot->payload + [
                'observed_at' => $snapshot->observed_at->toIso8601String(),
                'observation_id' => $snapshot->id, 'provenance_war_ids' => $snapshot->provenance_war_ids,
            ];
        }

        return $latest;
    }

    private function isClean(RaidNationObservation $snapshot, ?int $excludedWarId): bool
    {
        $effectiveAt = $snapshot->valid_from ?? $snapshot->observed_at;

        return $excludedWarId === null || (
            ! in_array($excludedWarId, $snapshot->provenance_war_ids ?? [], true)
            && ! WarAttack::query()->where('war_id', $excludedWarId)->where('date', '<=', $effectiveAt)->exists()
            && ! RaidAttackObservation::query()->where('war_id', $excludedWarId)->where('occurred_at', '<=', $effectiveAt)->exists()
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function withEconomy(
        array $payload,
        MarketPriceSet $prices,
        CarbonImmutable $asOf,
        bool $derivePopulation = true,
        ?int $excludedWarId = null,
    ): array {
        $hasEconomyVectors = isset($payload['daily_output'], $payload['daily_expenses'], $payload['daily_net']);
        $nation = new Nation;
        $nation->forceFill(Arr::except($payload, ['cities', 'active_wars', 'military_research', 'alliance', 'bounties']));
        $nation->setRelation('military', (new NationMilitary)->forceFill(Arr::only($payload, ['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies'])));
        $cities = collect($payload['cities'] ?? [])->map(fn (array $city) => (new City)->forceFill($city));
        $nation->setRelation('cities', $cities);
        try {
            if ($cities->count() !== (int) ($payload['num_cities'] ?? 0) || $cities->isEmpty()) {
                if (! $hasEconomyVectors) {
                    $payload['economy_unavailable'] = 'Complete city observations are unavailable.';
                }

                return $payload;
            }
            if ($hasEconomyVectors) {
                if (! $derivePopulation || ! $cities->contains(function (City $city): bool {
                    $population = $this->nullableInteger($city->population ?? null);

                    return $population === null || $population <= 0;
                })) {
                    return $payload;
                }
                $localContext = Nation::query()->find($payload['id'] ?? 0, ['id', 'treasure_income_modifier', 'color_turn_bonus', 'economy_context_synced_at']);
                if ($localContext?->economy_context_synced_at !== null
                    && $localContext->economy_context_synced_at->{$excludedWarId === null ? 'lte' : 'lt'}($asOf)) {
                    $nation->forceFill(Arr::only($localContext->getAttributes(), ['treasure_income_modifier', 'color_turn_bonus', 'economy_context_synced_at']));
                }
                $radiation = RadiationSnapshot::query()
                    ->where('snapshot_at', $excludedWarId === null ? '<=' : '<', $asOf)
                    ->latest('snapshot_at')->first();

                return $this->withDerivedCityPopulation($payload, $nation, $cities, $radiation, $prices, $asOf);
            }
            $localContext = Nation::query()->find($payload['id'] ?? 0, ['id', 'treasure_income_modifier', 'color_turn_bonus', 'economy_context_synced_at']);
            if ($localContext?->economy_context_synced_at !== null
                && $localContext->economy_context_synced_at->{$excludedWarId === null ? 'lte' : 'lt'}($asOf)) {
                $nation->forceFill(Arr::only($localContext->getAttributes(), ['treasure_income_modifier', 'color_turn_bonus', 'economy_context_synced_at']));
            }
            $radiation = RadiationSnapshot::query()
                ->where('snapshot_at', $excludedWarId === null ? '<=' : '<', $asOf)
                ->latest('snapshot_at')->first();
            $output = EconomyRules::emptyResourceBuffer();
            $expenses = EconomyRules::emptyResourceBuffer();
            $processes = [];
            $highestPopulation = 0;
            foreach ($cities as $city) {
                $metrics = $this->economy->calculateCityMetrics($nation, $city, $radiation, $prices, $asOf);
                $population = $this->nullableInteger($city->population ?? null)
                    ?? $this->nullableInteger($metrics['population'] ?? null)
                    ?? 0;
                if ($population > 0) {
                    $city->setAttribute('population', $population);
                    $highestPopulation = max($highestPopulation, $population);
                }
                foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                    $output[$resource] += $metrics['unrounded_resource_output_per_day'][$resource] ?? 0;
                    $expenses[$resource] += $metrics['unrounded_resource_expense_per_day'][$resource] ?? 0;
                }
                foreach (EconomyRules::MANUFACTURING_BUILDINGS as $field => $resource) {
                    $count = (int) ($city->{$field} ?? 0);
                    if ($count > 0 && $metrics['powered']) {
                        $vector = $this->economy->improvementOperatingVector($nation, $city, $field, $count, $radiation);
                        $processes[] = ['output' => array_filter($vector, fn ($v) => $v > 0), 'inputs' => array_map('abs', array_filter($vector, fn ($v) => $v < 0))];
                    }
                }
            }
            $net = $this->economy->calculateNation($nation, $radiation, $prices, $asOf)['resource_profit_per_day'];
            foreach (EconomyRules::RESOURCE_KEYS as $resource) {
                $difference = $net[$resource] - ($output[$resource] - $expenses[$resource]);
                if ($difference >= 0) {
                    $output[$resource] += $difference;
                } else {
                    $expenses[$resource] -= $difference;
                }
            }
            $payload['daily_output'] = $output;
            $payload['daily_expenses'] = $expenses;
            $payload['daily_net'] = $net;
            $payload['production_processes'] = $processes;
            $payload['cities'] = $cities->map(fn (City $city): array => $city->getAttributes())->all();
            if ($highestPopulation > 0) {
                $payload['highest_city_population'] = $highestPopulation;
            }
        } catch (Throwable $exception) {
            Log::warning('Raid economy context is unavailable', [
                'nation_id' => $payload['id'] ?? null,
                'as_of' => $asOf->toIso8601String(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $payload['economy_unavailable'] = 'Economy context is incomplete.';
        }

        return $payload;
    }

    /**
     * A declaration snapshot can contain the newly declared defensive war in
     * both the count and active-wars list. It is excluded from competition and
     * counter-risk inputs for the prediction being captured.
     */
    private function defensiveWarsForCapture(array $target, ?int $excludedWarId): int
    {
        $defensiveWars = max(0, (int) ($target['defensive_wars_count'] ?? $target['defensive_wars'] ?? 0));
        if ($excludedWarId === null || ! is_array($target['active_wars'] ?? null)) {
            return $defensiveWars;
        }

        $targetId = $this->nullableInteger($target['id'] ?? $target['nation_id'] ?? null);
        $excludedEntries = 0;
        foreach ($target['active_wars'] as $war) {
            if (! is_array($war)) {
                continue;
            }

            $warId = $this->nullableInteger($war['id'] ?? $war['war_id'] ?? null);
            $defenderId = $this->nullableInteger($war['def_id'] ?? $war['defender_id'] ?? null);
            if ($warId === $excludedWarId && ($targetId === null || $defenderId === null || $defenderId === $targetId)) {
                $excludedEntries++;
            }
        }

        return max(0, $defensiveWars - min(1, $excludedEntries));
    }

    private function withDerivedCityPopulation(
        array $payload,
        Nation $nation,
        Collection $cities,
        ?RadiationSnapshot $radiation,
        MarketPriceSet $prices,
        CarbonImmutable $asOf,
    ): array {
        $highestPopulation = $this->nullableInteger($payload['highest_city_population'] ?? null) ?? 0;
        foreach ($cities as $city) {
            $population = $this->nullableInteger($city->population ?? null);
            if ($population === null || $population <= 0) {
                $metrics = $this->economy->calculateCityMetrics($nation, $city, $radiation, $prices, $asOf);
                $population = $this->nullableInteger($metrics['population'] ?? null) ?? 0;
                if ($population > 0) {
                    $city->setAttribute('population', $population);
                }
            }
            $highestPopulation = max($highestPopulation, $population);
        }

        $payload['cities'] = $cities->map(fn (City $city): array => $city->getAttributes())->all();
        if ($highestPopulation > 0) {
            $payload['highest_city_population'] = $highestPopulation;
        }

        return $payload;
    }

    private function isUsableLootFraction(mixed $value): bool
    {
        if (is_string($value)) {
            $value = trim($value);
            $hasPercentSign = str_ends_with($value, '%');
            $value = rtrim($value, '%');
            if (! is_numeric($value)) {
                return false;
            }

            $value = (float) $value;
            if ($hasPercentSign || $value > 1) {
                $value /= 100;
            }
        } elseif (is_numeric($value) && ! is_bool($value)) {
            $value = (float) $value;
            if ($value > 1) {
                $value /= 100;
            }
        } else {
            return false;
        }

        return is_finite($value) && $value > 0 && $value < 1;
    }

    private function extractLootFraction(mixed $lootInfo): ?string
    {
        if (! is_string($lootInfo) || trim($lootInfo) === '') {
            return null;
        }

        if (preg_match('/(?:loot|fraction)[^0-9%]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%/i', $lootInfo, $matches) !== 1) {
            return null;
        }

        return $matches[1].'%';
    }
}
