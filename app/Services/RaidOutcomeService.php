<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\MarketPriceSet;
use App\Enums\WarTypeEnum;
use App\Models\RaidOutcomeAttack;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Models\WarAttack;
use App\Services\Calculators\MilitaryCostCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records immutable attack identities and reconciles actual raid outcomes.
 */
final class RaidOutcomeService
{
    public function __construct(
        private readonly MilitaryCostCalculator $militaryCostCalculator,
        private readonly RuntimeCapabilities $runtimeCapabilities,
        private readonly QueryService $queries,
    ) {}

    /** @var list<string> */
    private const LOOT_RESOURCES = [
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
    ];

    /** @var list<string> */
    private const COST_RESOURCES = [
        'gasoline',
        'munitions',
        'aluminum',
        'steel',
    ];

    /**
     * Record an already-persisted world attack.
     */
    public function recordAttack(
        int $attackId,
        ?int $warId = null,
        ?CarbonImmutable $observedAt = null,
    ): ?RaidOutcomeAttack {
        $attack = WarAttack::query()->find($attackId);

        if ($attack === null) {
            Log::warning('Raid outcome attack could not be recorded because the world attack is unavailable.', [
                'attack_id' => $attackId,
                'war_id' => $warId,
            ]);

            return null;
        }

        if ($warId !== null && (int) $attack->getAttribute('war_id') !== $warId) {
            Log::warning('Raid outcome attack event did not match the persisted attack war.', [
                'attack_id' => $attackId,
                'event_war_id' => $warId,
                'attack_war_id' => $attack->getAttribute('war_id'),
            ]);

            return null;
        }

        return $this->recordPayload($attack->toArray(), $observedAt);
    }

    /**
     * Record a normalized payload. This is useful for refresh/reconciliation
     * jobs when the raw world row has already been pruned.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordPayload(
        array $payload,
        ?CarbonImmutable $observedAt = null,
        ?bool $isLate = null,
        bool $reconcile = true,
    ): ?RaidOutcomeAttack {
        if (! $this->canWrite()) {
            return null;
        }

        $attackId = $this->positiveInteger($payload['id'] ?? $payload['attack_id'] ?? null);
        $warId = $this->positiveInteger($payload['war_id'] ?? $payload['warId'] ?? null);

        if ($attackId === null || $warId === null) {
            return null;
        }

        $existingIdentity = RaidOutcomeAttack::query()
            ->where('attack_id', $attackId)
            ->first();
        if ($existingIdentity !== null && (int) $existingIdentity->war_id !== $warId) {
            Log::warning('Raid outcome attack payload attempted to move a global attack identity between wars.', [
                'attack_id' => $attackId,
                'existing_war_id' => $existingIdentity->war_id,
                'payload_war_id' => $warId,
            ]);

            return null;
        }

        $war = War::query()->find($warId);
        $prediction = RaidPrediction::query()->where('war_id', $warId)->first();

        if ($prediction === null
            && ($war === null || strtoupper((string) $war->getAttribute('war_type')) !== WarTypeEnum::RAID->value)) {
            return null;
        }

        $payloadAttackerId = $this->positiveInteger(
            $payload['att_id'] ?? $payload['attacker_nation_id'] ?? $payload['attacker_id'] ?? null,
        );
        $payloadDefenderId = $this->positiveInteger(
            $payload['def_id'] ?? $payload['defender_nation_id'] ?? $payload['defender_id'] ?? null,
        );
        $expectedAttackerId = $prediction?->attacker_nation_id
            ?? $this->positiveInteger($war?->getAttribute('att_id'));
        $expectedDefenderId = $prediction?->target_nation_id
            ?? $this->positiveInteger($war?->getAttribute('def_id'));
        if (! $this->matchesWarParticipants(
            $payloadAttackerId,
            $payloadDefenderId,
            $expectedAttackerId,
            $expectedDefenderId,
        )) {
            Log::warning('Raid outcome attack payload did not match the declared war participants.', [
                'attack_id' => $attackId,
                'war_id' => $warId,
                'attacker_nation_id' => $payloadAttackerId,
                'defender_nation_id' => $payloadDefenderId,
                'expected_attacker_nation_id' => $expectedAttackerId,
                'expected_defender_nation_id' => $expectedDefenderId,
            ]);

            return null;
        }

        $normalized = $this->normalizePayload($payload);
        $observedAt ??= CarbonImmutable::now();
        $isLate ??= ($prediction?->isTerminal() ?? false) || $this->warIsTerminal($war);

        $values = [
            'raid_prediction_id' => $prediction?->id,
            'war_id' => $warId,
            'attack_id' => $attackId,
            'attacker_nation_id' => $normalized['attacker_nation_id'],
            'defender_nation_id' => $normalized['defender_nation_id'],
            'attack_at' => $normalized['attack_at'],
            'attack_type' => $normalized['attack_type'],
            'victor' => $normalized['victor'],
            'success' => $normalized['success'],
            'money_looted' => $normalized['money_looted'],
            'money_stolen' => $normalized['money_stolen'],
            'money_destroyed' => $normalized['money_destroyed'],
            'loot_resources' => $normalized['loot_resources'],
            'bank_loot' => $normalized['bank_loot'],
            'cost_resources' => $normalized['cost_resources'],
            'defender_cost_resources' => $normalized['defender_cost_resources'],
            'casualties' => $normalized['casualties'],
            'defender_casualties' => $normalized['defender_casualties'],
            'infrastructure' => $normalized['infrastructure'],
            'attacker_infrastructure' => $normalized['attacker_infrastructure'],
            'defender_infrastructure' => $normalized['defender_infrastructure'],
            'payload' => $payload,
            'payload_hash' => $normalized['payload_hash'],
            'is_late' => $isLate,
            'observed_at' => $observedAt,
        ];

        $outcome = DB::transaction(function () use ($warId, $attackId, $values): ?RaidOutcomeAttack {
            $existing = RaidOutcomeAttack::query()
                ->where('war_id', $warId)
                ->where('attack_id', $attackId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash === $values['payload_hash']) {
                    if ($existing->raid_prediction_id === null && $values['raid_prediction_id'] !== null) {
                        $existing->forceFill([
                            'raid_prediction_id' => $values['raid_prediction_id'],
                        ])->saveQuietly();
                    }

                    if ($values['is_late'] && ! $existing->is_late) {
                        $existing->forceFill(['is_late' => true])->saveQuietly();
                    }

                    return $existing->refresh();
                }

                $existing->forceFill([
                    ...$values,
                    'revision' => ((int) $existing->revision) + 1,
                ])->saveQuietly();

                return $existing->refresh();
            }

            try {
                return RaidOutcomeAttack::query()->create([
                    ...$values,
                    'revision' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                $concurrent = RaidOutcomeAttack::query()
                    ->where('attack_id', $attackId)
                    ->lockForUpdate()
                    ->first();

                if ($concurrent === null) {
                    throw new \RuntimeException('Raid outcome attack was inserted concurrently but could not be reloaded.');
                }

                if ((int) $concurrent->war_id !== $warId) {
                    Log::warning('Concurrent raid outcome payload attempted to move a global attack identity between wars.', [
                        'attack_id' => $attackId,
                        'existing_war_id' => $concurrent->war_id,
                        'payload_war_id' => $warId,
                    ]);

                    return null;
                }

                return $concurrent;
            }
        });

        if ($prediction !== null && $reconcile) {
            $this->reconcile($prediction);
        }

        return $outcome;
    }

    /**
     * Attach pre-declaration attack evidence and reconcile one war.
     */
    public function reconcile(int|RaidPrediction $predictionOrWarId): ?RaidPrediction
    {
        if (! $this->canWrite()) {
            return null;
        }

        $prediction = $predictionOrWarId instanceof RaidPrediction
            ? $predictionOrWarId->refresh()
            : RaidPrediction::query()->where('war_id', $predictionOrWarId)->first();

        if ($prediction === null) {
            return null;
        }

        RaidOutcomeAttack::query()
            ->where('war_id', (int) $prediction->war_id)
            ->whereNull('raid_prediction_id')
            ->update(['raid_prediction_id' => $prediction->id]);

        $war = War::query()->find($prediction->war_id);
        $wasFinalizedBeforeWarPruning = $war === null
            && $prediction->outcome_finalized_at !== null
            && $prediction->isTerminal();
        $status = $wasFinalizedBeforeWarPruning
            ? (string) $prediction->outcome_status
            : $this->outcomeStatus($war, (int) $prediction->attacker_nation_id);
        $terminal = $this->isTerminalStatus($status);
        $evidence = RaidOutcomeAttack::query()
            ->where('raid_prediction_id', $prediction->id)
            ->orderBy('attack_at')
            ->orderBy('id')
            ->get();

        // Raw war rows are retained only for a bounded period. Once a
        // terminal result has been finalized, an empty normalized ledger
        // after that retention boundary must not turn the previously known
        // actuals into zeroes or move the endpoint forward to now. A late
        // normalized attack still takes the normal path below and can update
        // the finalized totals.
        if ($wasFinalizedBeforeWarPruning && $evidence->isEmpty()) {
            return $prediction;
        }

        $evidenceCompleteness = $wasFinalizedBeforeWarPruning
            ? [
                'status' => 'preserved',
                'reason' => 'The terminal war row is no longer retained; the finalized outcome is preserved.',
                'expected' => [],
                'observed' => [],
                'mismatches' => [],
                'missing' => [],
            ]
            : ($terminal
            ? $this->evidenceCompleteness($war, $evidence)
            : [
                'status' => 'pending',
                'reason' => null,
                'expected' => [],
                'observed' => [],
                'mismatches' => [],
                'missing' => [],
            ]);
        $refreshMetadata = [];
        if ($terminal && ! $wasFinalizedBeforeWarPruning && $evidenceCompleteness['status'] !== 'complete'
            && $this->shouldRefreshTerminalEvidence($prediction)) {
            $refreshMetadata = $this->refreshTerminalEvidence($war, $prediction);
            if (($refreshMetadata['attempted'] ?? false) === true) {
                $evidence = RaidOutcomeAttack::query()
                    ->where('raid_prediction_id', $prediction->id)
                    ->orderBy('attack_at')
                    ->orderBy('id')
                    ->get();
                $evidenceCompleteness = $this->evidenceCompleteness($war, $evidence);
            }
        }

        $lootPrices = $this->prices($prediction->price_snapshot, 'liquidation');
        $costPrices = $this->prices($prediction->price_snapshot, 'acquisition');
        $attackerId = (int) $prediction->attacker_nation_id;
        $offensiveEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->attacker_nation_id === $attackerId,
        );
        $lootEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => $this->creditsLootToParticipant($attack, $attackerId),
        );
        $participantEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->attacker_nation_id === $attackerId
                || (int) $attack->defender_nation_id === $attackerId,
        );
        $defensiveEvidence = $participantEvidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->defender_nation_id === $attackerId,
        );
        $lootComponents = $this->sumLoot($lootEvidence);
        $loot = $lootComponents['total'];
        $nationLoot = $lootComponents['nation'];
        $groundCash = $lootComponents['ground_cash'];
        $bankLoot = $lootComponents['bank'];
        $bounty = $this->bountyResult($prediction, $lootEvidence, $status);
        $costs = $this->sumCosts($participantEvidence, $attackerId);
        $losses = $this->sumLosses($participantEvidence, $attackerId);
        $losses = $this->applyStrategicUnitUsage($losses, $war, $attackerId);
        $lootValue = $this->valueLoot($loot, $lootPrices);
        $nationLootValue = $this->valueLoot($nationLoot, $lootPrices);
        $bankLootValue = $this->valueLoot($bankLoot, $lootPrices);
        $resourceCosts = $this->valueResources($costs, $costPrices);
        $militaryLossValue = $this->valueMilitaryLosses($losses, $prediction, $costPrices);
        $infraLossValue = $this->infrastructureLossValue($losses);
        $gross = $lootValue === null || $bounty['value'] === null
            ? null
            : $lootValue + $bounty['value'];
        $net = $gross === null || $resourceCosts === null || $militaryLossValue === null || $infraLossValue === null
            ? null
            : $gross - $resourceCosts - $militaryLossValue - $infraLossValue;
        $valuationMissing = [];
        if ($costs !== [] && $resourceCosts === null) {
            $valuationMissing[] = 'consumables.valuation';
        }
        if ($this->hasPositiveMilitaryLosses($losses) && $militaryLossValue === null) {
            $valuationMissing[] = 'military_losses.valuation';
        }
        if (($losses['infrastructure_unvalued'] ?? 0.0) > 0.0 && $infraLossValue === null) {
            $valuationMissing[] = 'infrastructure_losses.valuation';
        }
        if ($terminal && $valuationMissing !== []) {
            $evidenceCompleteness = [
                ...$evidenceCompleteness,
                'status' => 'incomplete',
                'reason' => 'One or more recorded raid costs cannot be valued using the immutable price snapshot.',
                'missing' => array_values(array_unique([
                    ...($evidenceCompleteness['missing'] ?? []),
                    ...$valuationMissing,
                ])),
            ];
        }
        if ($terminal && $evidenceCompleteness['status'] === 'complete' && $bounty['required'] && ! $bounty['known']) {
            $evidenceCompleteness = [
                ...$evidenceCompleteness,
                'status' => 'incomplete',
                'reason' => 'The prediction included an eligible bounty, but the payout was not present in terminal evidence.',
                'missing' => array_values(array_unique([
                    ...($evidenceCompleteness['missing'] ?? []),
                    'bounty_payout',
                ])),
            ];
        }
        $outcomeComplete = ! $terminal || in_array($evidenceCompleteness['status'], ['complete', 'preserved'], true);
        $finalizedAt = $prediction->outcome_finalized_at;
        if ($terminal && $outcomeComplete && $finalizedAt === null) {
            $finalizedAt = CarbonImmutable::now();
        }

        $actualDuration = $outcomeComplete
            ? $this->durationHours($prediction, $war, $terminal, $evidence)
            : null;
        $previousMetadata = is_array($prediction->outcome_metadata) ? $prediction->outcome_metadata : [];
        $previousRefresh = is_array($previousMetadata['evidence_refresh'] ?? null)
            ? $previousMetadata['evidence_refresh']
            : [];
        $refreshMetadata = $refreshMetadata === [] ? $previousRefresh : $refreshMetadata;
        $actualComponents = [
            'gross_loot' => $this->roundNullable($gross),
            'nation_loot' => $this->roundNullable($nationLootValue),
            'ground_loot' => round($groundCash, 2),
            'bank_loot' => $this->roundNullable($bankLootValue),
            'bounty' => $this->roundNullable($bounty['value']),
            'consumables' => $this->roundNullable($resourceCosts),
            'military_losses' => $this->roundNullable($militaryLossValue),
            'infrastructure_losses' => $this->roundNullable($infraLossValue),
        ];
        $prediction->forceFill([
            'outcome_status' => $status,
            'actual_attack_count' => $evidence->count(),
            'actual_gross_loot' => $outcomeComplete ? $this->roundNullable($gross) : null,
            'actual_net' => $outcomeComplete ? $this->roundNullable($net) : null,
            'actual_duration_hours' => $actualDuration,
            'actual_components' => $outcomeComplete ? $actualComponents : null,
            'actual_loot_resources' => $outcomeComplete ? $loot : null,
            'actual_bank_loot' => $outcomeComplete ? $bankLoot : null,
            'actual_cost_resources' => $outcomeComplete ? $costs : null,
            'actual_losses' => $outcomeComplete ? $losses : null,
            'outcome_metadata' => [
                'evidence_attack_count' => $evidence->count(),
                'member_attack_count' => $participantEvidence->count(),
                'offensive_attack_count' => $offensiveEvidence->count(),
                'loot_credit_attack_count' => $lootEvidence->count(),
                'defensive_attack_count' => $defensiveEvidence->count(),
                'late_attack_count' => $evidence->where('is_late', true)->count(),
                'correction_count' => $evidence->sum(fn (RaidOutcomeAttack $attack): int => max(0, (int) $attack->revision - 1)),
                'prices' => $prediction->price_snapshot,
                'valuation_basis' => [
                    'loot' => 'liquidation',
                    'costs_and_losses' => 'acquisition',
                ],
                'loot_components' => [
                    'nation' => $nationLoot,
                    'ground_cash' => round($groundCash, 4),
                    'bank' => $bankLoot,
                ],
                'bounty' => $bounty,
                'stockpile_estimation' => [
                    'status' => 'conditional',
                    'basis' => 'realized_loot_vs_prediction',
                    'stockpile_error' => null,
                    'predicted_loot_resources' => $prediction->loot_resources,
                    'observed_loot_resources' => $loot,
                    'resource_error' => $outcomeComplete
                        ? $this->resourceError($prediction->loot_resources, $loot)
                        : null,
                ],
                'plan_adherence' => $this->planAdherence($prediction, $offensiveEvidence),
                'evidence_status' => $evidenceCompleteness['status'],
                'evidence_reason' => $evidenceCompleteness['reason'],
                'evidence_expected' => $evidenceCompleteness['expected'],
                'evidence_observed' => $evidenceCompleteness['observed'],
                'evidence_mismatches' => $evidenceCompleteness['mismatches'],
                'evidence_missing' => $evidenceCompleteness['missing'] ?? [],
                'valuation_missing' => $valuationMissing,
                'observed_partial' => $outcomeComplete ? null : [
                    'gross_loot' => $this->roundNullable($gross),
                    'components' => $actualComponents,
                    'loot_resources' => $loot,
                    'bank_loot' => $bankLoot,
                    'cost_resources' => $costs,
                    'losses' => $losses,
                ],
                'evidence_refresh' => $refreshMetadata,
                'duration_basis' => $this->durationBasis($war, $evidence, $actualDuration),
            ],
            'outcome_finalized_at' => $finalizedAt,
        ])->saveQuietly();

        return $prediction->refresh();
    }

    /** Alias used by reconciliation callers. */
    public function reconcileWar(int $warId): ?RaidPrediction
    {
        return $this->reconcile($warId);
    }

    /**
     * Compare the bounded attack ledger with the terminal war aggregates.
     *
     * A terminal state alone is not enough to call a result final. Raw attack
     * rows can arrive after the state event or be pruned before a tenant has
     * recorded them, so a partial ledger is kept explicitly incomplete.
     *
     * @param  Collection<int, RaidOutcomeAttack>  $evidence
     * @return array{status: string, reason: string|null, expected: array<string, float>, observed: array<string, float>, mismatches: array<string, array{expected: float, observed: float, difference: float}>, missing: list<string>}
     */
    private function evidenceCompleteness(?War $war, Collection $evidence): array
    {
        if ($war === null) {
            return [
                'status' => 'incomplete',
                'reason' => 'The terminal war row is unavailable.',
                'expected' => [],
                'observed' => [],
                'mismatches' => [],
                'missing' => [],
            ];
        }

        $warAttackerId = (int) $war->getAttribute('att_id');
        $warDefenderId = (int) $war->getAttribute('def_id');
        if ($warAttackerId <= 0 || $warDefenderId <= 0) {
            return [
                'status' => 'incomplete',
                'reason' => 'The terminal war participants are unavailable.',
                'expected' => [],
                'observed' => [],
                'mismatches' => [],
                'missing' => [],
            ];
        }

        $expected = [];
        $observed = [];
        $missing = [];
        $add = function (string $key, mixed $expectedValue, float $observedValue) use (&$expected, &$observed, &$missing): void {
            if (! is_numeric($expectedValue)) {
                $missing[] = $key;

                return;
            }

            $expected[$key] = round((float) $expectedValue, 4);
            $observed[$key] = round($observedValue, 4);
        };

        $attackerEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->attacker_nation_id === $warAttackerId,
        );
        $attackerIncomingEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->defender_nation_id === $warAttackerId,
        );
        $defenderEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->attacker_nation_id === $warDefenderId,
        );
        $defenderIncomingEvidence = $evidence->filter(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->defender_nation_id === $warDefenderId,
        );

        $resources = [
            'gasoline' => ['att_gas_used', 'def_gas_used'],
            'munitions' => ['att_mun_used', 'def_mun_used'],
            'aluminum' => ['att_alum_used', 'def_alum_used'],
            'steel' => ['att_steel_used', 'def_steel_used'],
        ];
        foreach ($resources as $resource => [$attackerField, $defenderField]) {
            $add(
                'attacker.costs.'.$resource,
                $war->getAttribute($attackerField),
                $this->sumParticipantResource(
                    $attackerEvidence,
                    $attackerIncomingEvidence,
                    'cost_resources',
                    $resource,
                ),
            );
            $add(
                'defender.costs.'.$resource,
                $war->getAttribute($defenderField),
                $this->sumParticipantResource(
                    $defenderEvidence,
                    $defenderIncomingEvidence,
                    'cost_resources',
                    $resource,
                ),
            );
        }

        $units = ['soldiers', 'tanks', 'aircraft', 'ships'];
        foreach ($units as $unit) {
            $add(
                'attacker.casualties.'.$unit,
                $war->getAttribute('att_'.$unit.'_lost'),
                $this->sumParticipantResource(
                    $attackerEvidence,
                    $attackerIncomingEvidence,
                    'casualties',
                    $unit,
                ),
            );
            $add(
                'defender.casualties.'.$unit,
                $war->getAttribute('def_'.$unit.'_lost'),
                $this->sumParticipantResource(
                    $defenderEvidence,
                    $defenderIncomingEvidence,
                    'casualties',
                    $unit,
                ),
            );
        }

        // The world war totals are damage inflicted by each side. For an
        // attack row, the target-side infrastructure fields contain that
        // damage, while attacker_infrastructure records losses to the actor.
        $add(
            'attacker.infrastructure_destroyed_value',
            $war->getAttribute('att_infra_destroyed_value'),
            $this->sumInfrastructureDamage($attackerEvidence),
        );
        $add(
            'defender.infrastructure_destroyed_value',
            $war->getAttribute('def_infra_destroyed_value'),
            $this->sumInfrastructureDamage($defenderEvidence),
        );
        $add(
            'attacker.money_looted',
            $war->getAttribute('att_money_looted'),
            (float) $attackerEvidence->sum(fn (RaidOutcomeAttack $attack): float => (float) $attack->money_looted),
        );
        $add(
            'defender.money_looted',
            $war->getAttribute('def_money_looted'),
            (float) $defenderEvidence->sum(fn (RaidOutcomeAttack $attack): float => (float) $attack->money_looted),
        );

        if ($evidence->isEmpty()) {
            return [
                'status' => 'incomplete',
                'reason' => 'No attack evidence has been observed for the terminal war.',
                'expected' => $expected,
                'observed' => $observed,
                'mismatches' => [],
                'missing' => array_values(array_unique($missing)),
            ];
        }

        if ($missing !== []) {
            return [
                'status' => 'incomplete',
                'reason' => 'Terminal war aggregates are incomplete.',
                'expected' => $expected,
                'observed' => $observed,
                'mismatches' => [],
                'missing' => array_values(array_unique($missing)),
            ];
        }

        $mismatches = [];
        foreach ($expected as $key => $expectedValue) {
            $observedValue = $observed[$key] ?? 0.0;
            $difference = round($observedValue - $expectedValue, 4);
            $tolerance = max(0.01, abs($expectedValue) * 0.001);
            if (abs($difference) > $tolerance) {
                $mismatches[$key] = [
                    'expected' => $expectedValue,
                    'observed' => $observedValue,
                    'difference' => $difference,
                ];
            }
        }

        $missingIdentity = $evidence->contains(
            fn (RaidOutcomeAttack $attack): bool => (int) $attack->attacker_nation_id <= 0
                || (int) $attack->defender_nation_id <= 0,
        );
        if ($missingIdentity) {
            return [
                'status' => 'incomplete',
                'reason' => 'Attack evidence is missing a participant identity.',
                'expected' => $expected,
                'observed' => $observed,
                'mismatches' => $mismatches,
                'missing' => [],
            ];
        }

        return [
            'status' => $mismatches === [] ? 'complete' : 'incomplete',
            'reason' => $mismatches === [] ? null : 'Terminal war aggregates exceed the observed attack ledger.',
            'expected' => $expected,
            'observed' => $observed,
            'mismatches' => $mismatches,
            'missing' => [],
        ];
    }

    /** @param iterable<RaidOutcomeAttack> $attacks */
    private function sumResource(iterable $attacks, string $field, string $resource): float
    {
        $total = 0.0;
        foreach ($attacks as $attack) {
            $values = (array) ($attack->{$field} ?? []);
            if (is_numeric($values[$resource] ?? null)) {
                $total += (float) $values[$resource];
            }
        }

        return $total;
    }

    /**
     * Sum one participant's side of every attack. The participant is the
     * attacker in the first iterable and the defender in the second.
     *
     * @param  iterable<RaidOutcomeAttack>  $outgoing
     * @param  iterable<RaidOutcomeAttack>  $incoming
     */
    private function sumParticipantResource(
        iterable $outgoing,
        iterable $incoming,
        string $field,
        string $resource,
    ): float {
        return $this->sumResource($outgoing, $field, $resource)
            + $this->sumResource($incoming, 'defender_'.$field, $resource);
    }

    /** @param iterable<RaidOutcomeAttack> $attacks */
    private function sumInfrastructureDamage(iterable $attacks): float
    {
        $total = 0.0;
        foreach ($attacks as $attack) {
            $values = (array) ($attack->defender_infrastructure ?? []);
            $values = $values === [] ? (array) ($attack->infrastructure ?? []) : $values;
            if (is_numeric($values['destroyed_value'] ?? null)) {
                $total += (float) $values['destroyed_value'];
            }
        }

        return $total;
    }

    /**
     * Resolve the bounty component without inferring a payout from the
     * declaration snapshot. A payout is only known when an attack payload
     * carries an explicit amount; an eligible-but-unobserved bounty keeps the
     * terminal outcome incomplete.
     *
     * @param  iterable<RaidOutcomeAttack>  $attacks
     * @return array{known: bool, required: bool, value: float|null, expected: float|null, basis: string}
     */
    private function bountyResult(RaidPrediction $prediction, iterable $attacks, string $outcomeStatus): array
    {
        $expected = $this->expectedBounty($prediction);
        $observed = null;
        foreach ($attacks as $attack) {
            $amount = $this->explicitBounty((array) ($attack->payload ?? []));
            if ($amount === null) {
                continue;
            }

            $observed = ($observed ?? 0.0) + $amount;
        }

        if ($observed !== null) {
            return [
                'known' => true,
                'required' => $expected['required'],
                'value' => round(max(0.0, $observed), 4),
                'expected' => $expected['value'],
                'basis' => 'attack_payload',
            ];
        }

        if (in_array($outcomeStatus, [
            RaidPrediction::OUTCOME_LOST,
            RaidPrediction::OUTCOME_PEACE,
            RaidPrediction::OUTCOME_EXPIRED,
        ], true)) {
            return [
                'known' => true,
                'required' => false,
                'value' => 0.0,
                'expected' => $expected['value'],
                'basis' => 'terminal_without_attacker_victory',
            ];
        }

        if (! $expected['required']) {
            return [
                'known' => true,
                'required' => false,
                'value' => 0.0,
                'expected' => $expected['value'],
                'basis' => $expected['basis'],
            ];
        }

        return [
            'known' => false,
            'required' => true,
            'value' => null,
            'expected' => $expected['value'],
            'basis' => $expected['basis'],
        ];
    }

    /**
     * @return array{known: bool, required: bool, value: float|null, basis: string}
     */
    private function expectedBounty(RaidPrediction $prediction): array
    {
        $components = is_array($prediction->components) ? $prediction->components : [];
        if (array_key_exists('bounty', $components)) {
            $value = is_numeric($components['bounty'])
                ? max(0.0, (float) $components['bounty'])
                : null;

            return [
                'known' => $value !== null,
                'required' => $value === null || $value > 0.0,
                'value' => $value,
                'basis' => 'prediction_components',
            ];
        }

        $context = is_array($prediction->context_snapshot) ? $prediction->context_snapshot : [];
        foreach (['bounty_snapshot', 'bounty', 'bounties'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $this->bountySnapshotValue($context[$key]);

            return [
                'known' => $value !== null,
                'required' => $value === null || $value > 0.0,
                'value' => $value,
                'basis' => 'prediction_context',
            ];
        }

        return [
            'known' => false,
            'required' => false,
            'value' => null,
            'basis' => 'unavailable',
        ];
    }

    private function bountySnapshotValue(mixed $snapshot): ?float
    {
        if (is_numeric($snapshot)) {
            return max(0.0, (float) $snapshot);
        }

        if (! is_array($snapshot)) {
            return null;
        }

        if (array_key_exists('amount', $snapshot) || array_key_exists('value', $snapshot) || array_key_exists('payout', $snapshot)) {
            $value = $snapshot['amount'] ?? $snapshot['value'] ?? $snapshot['payout'] ?? null;

            return is_numeric($value) ? max(0.0, (float) $value) : null;
        }

        $total = 0.0;
        $found = false;
        foreach ($snapshot as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = strtoupper((string) ($entry['war_type'] ?? $entry['type'] ?? ''));
            if ($type !== 'RAID') {
                continue;
            }

            $value = $entry['amount'] ?? $entry['value'] ?? $entry['payout'] ?? null;
            if (is_numeric($value)) {
                $total += max(0.0, (float) $value);
                $found = true;
            }
        }

        return $found ? $total : 0.0;
    }

    private function explicitBounty(array $payload): ?float
    {
        foreach (['bounty_payout', 'bounty_amount', 'raid_bounty', 'bounty'] as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $this->bountySnapshotValue($payload[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $lootInfo = $payload['loot_info'] ?? null;
        if (is_string($lootInfo) && $lootInfo !== '') {
            $decoded = json_decode($lootInfo, true);
            if (is_array($decoded)) {
                foreach (['bounty_payout', 'bounty_amount', 'raid_bounty', 'bounty'] as $key) {
                    if (array_key_exists($key, $decoded)) {
                        $value = $this->bountySnapshotValue($decoded[$key]);
                        if ($value !== null) {
                            return $value;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            RaidPrediction::OUTCOME_WON,
            RaidPrediction::OUTCOME_LOST,
            RaidPrediction::OUTCOME_PEACE,
            RaidPrediction::OUTCOME_EXPIRED,
        ], true);
    }

    private function shouldRefreshTerminalEvidence(RaidPrediction $prediction): bool
    {
        $metadata = is_array($prediction->outcome_metadata) ? $prediction->outcome_metadata : [];
        $lastAttempt = $this->timestamp(data_get($metadata, 'evidence_refresh.attempted_at'));
        if ($lastAttempt === null) {
            return true;
        }

        $cooldown = max(60, (int) config('raids.reconciliation_refresh_seconds', 600));

        return $lastAttempt->diffInSeconds(CarbonImmutable::now()) >= $cooldown;
    }

    /**
     * Fetch at most one bounded page for a terminal war. This is only used
     * after local aggregates show that the tenant ledger is incomplete.
     *
     * @return array<string, mixed>
     */
    private function refreshTerminalEvidence(?War $war, RaidPrediction $prediction): array
    {
        $attemptedAt = CarbonImmutable::now();
        $result = [
            'attempted' => true,
            'attempted_at' => $attemptedAt->toIso8601String(),
            'status' => 'unavailable',
            'count' => 0,
        ];

        if ($war === null) {
            return $result + ['error' => 'Terminal war row is unavailable.'];
        }

        try {
            $pageSize = max(1, min(100, (int) config('raids.reconciliation_page_size', 100)));
            $query = (new GraphQLQueryBuilder)
                ->setRootField('wars')
                ->addArgument([
                    'id' => [(int) $war->getKey()],
                    'first' => $pageSize,
                    'page' => 1,
                ])
                ->addNestedField('data', function (GraphQLQueryBuilder $builder): void {
                    $builder->addFields([
                        'id',
                        'att_id',
                        'def_id',
                    ])->addNestedField('attacks', function (GraphQLQueryBuilder $attack): void {
                        $attack->addFields([
                            'id', 'date', 'att_id', 'def_id', 'type', 'victor', 'success',
                            'money_stolen', 'money_looted', 'money_destroyed', 'loot_info',
                            'coal_looted', 'oil_looted', 'uranium_looted', 'iron_looted',
                            'bauxite_looted', 'lead_looted', 'gasoline_looted', 'munitions_looted',
                            'steel_looted', 'aluminum_looted', 'food_looted',
                            'infra_destroyed', 'infra_destroyed_value',
                            'att_mun_used', 'def_mun_used', 'att_gas_used', 'def_gas_used',
                            'att_alum_used', 'def_alum_used', 'att_steel_used', 'def_steel_used',
                            'att_soldiers_lost', 'def_soldiers_lost', 'att_tanks_lost', 'def_tanks_lost',
                            'att_aircraft_lost', 'def_aircraft_lost', 'att_ships_lost', 'def_ships_lost',
                            'att_infra_destroyed', 'def_infra_destroyed',
                            'att_infra_destroyed_value', 'def_infra_destroyed_value',
                        ]);
                    });
                });

            $rows = (array) $this->queries->sendQuery($query, handlePagination: false);
            $warPayload = collect($rows)
                ->map(fn (mixed $row): array => $this->arrayPayload($row))
                ->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $war->getKey());
            if ($warPayload === null) {
                return $result + ['error' => 'The terminal war was not returned by the bounded refresh.'];
            }

            $attacks = $warPayload['attacks'] ?? [];
            if (is_array($attacks) && isset($attacks['data']) && is_array($attacks['data'])) {
                $attacks = $attacks['data'];
            }
            if (! is_array($attacks)) {
                $attacks = [];
            }

            $stored = 0;
            foreach ($attacks as $attack) {
                $attack = $this->arrayPayload($attack);
                if ($this->positiveInteger($attack['id'] ?? null) === null) {
                    continue;
                }

                $attack['war_id'] = (int) $war->getKey();
                if ($this->recordPayload($attack, $attemptedAt, true, false) !== null) {
                    $stored++;
                }
            }

            return $result + [
                'status' => $stored > 0 ? 'refreshed' : 'empty',
                'count' => $stored,
            ];
        } catch (Throwable $exception) {
            Log::warning('Terminal raid evidence refresh failed.', [
                'prediction_id' => $prediction->id,
                'war_id' => $war->getKey(),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $result + [
                'status' => 'failed',
                'error' => 'The bounded terminal evidence refresh failed.',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function arrayPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        return [];
    }

    /** @param array<string, mixed>|null $prices @return array<string, float> */
    private function prices(?array $prices, string $basis): array
    {
        $selected = $prices[$basis]
            ?? ($basis === 'liquidation' ? ($prices['sell'] ?? null) : ($prices['buy'] ?? null))
            ?? $prices;

        if (! is_array($selected)) {
            return ['money' => 1.0];
        }

        $result = ['money' => 1.0];
        foreach ($selected as $resource => $value) {
            if (is_numeric($value)) {
                $result[(string) $resource] = (float) $value;
            }
        }

        return $result;
    }

    /**
     * @param  iterable<RaidOutcomeAttack>  $attacks
     * @return array{
     *     nation: array<string, float>,
     *     ground_cash: float,
     *     bank: array<string, float>,
     *     total: array<string, float>
     * }
     */
    private function sumLoot(iterable $attacks): array
    {
        $nation = ['money' => 0.0];
        foreach (self::LOOT_RESOURCES as $resource) {
            $nation[$resource] = 0.0;
        }
        $bank = ['money' => 0.0];
        $groundCash = 0.0;

        foreach ($attacks as $attack) {
            $type = strtoupper((string) ($attack->attack_type ?? ''));
            $loot = (array) ($attack->loot_resources ?? []);
            $bankLoot = (array) ($attack->bank_loot ?? []);

            if ($type === 'ALLIANCELOOT') {
                // Older normalized rows may have stored ALLIANCELOOT in the
                // nation bucket. Prefer that raw bucket when it has values;
                // current rows store it canonically in bank_loot.
                $rawLoot = $this->hasPositiveValue($loot) || (float) $attack->money_looted > 0.0;
                $source = $rawLoot ? $loot : $bankLoot;
                foreach ($source as $resource => $amount) {
                    if (is_numeric($amount)) {
                        $bank[(string) $resource] = ($bank[(string) $resource] ?? 0.0) + (float) $amount;
                    }
                }
                if ($rawLoot || (float) $attack->money_looted > 0.0) {
                    $bank['money'] += (float) $attack->money_looted;
                }
            } else {
                $nation['money'] += (float) $attack->money_looted;
                foreach ($loot as $resource => $amount) {
                    if (is_numeric($amount)) {
                        $nation[(string) $resource] = ($nation[(string) $resource] ?? 0.0) + (float) $amount;
                    }
                }
            }

            if ($type === 'GROUND') {
                $groundCash += (float) $attack->money_stolen;
            }

            if ($type !== 'ALLIANCELOOT') {
                foreach ($bankLoot as $resource => $amount) {
                    if (is_numeric($amount)) {
                        $bank[(string) $resource] = ($bank[(string) $resource] ?? 0.0) + (float) $amount;
                    }
                }
            }
        }

        $nation = $this->roundMap($nation);
        $bank = $this->roundMap($bank);
        $total = $nation;
        $total['money'] += $groundCash + ($bank['money'] ?? 0.0);
        foreach ($bank as $resource => $amount) {
            if ($resource !== 'money') {
                $total[$resource] = ($total[$resource] ?? 0.0) + (float) $amount;
            }
        }

        return [
            'nation' => $nation,
            'ground_cash' => round($groundCash, 4),
            'bank' => $bank,
            'total' => $this->roundMap($total),
        ];
    }

    /** @param array<string, mixed> $values */
    private function hasPositiveValue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0.0) {
                return true;
            }
        }

        return false;
    }

    private function creditsLootToParticipant(RaidOutcomeAttack $attack, int $participantId): bool
    {
        $type = strtoupper((string) ($attack->attack_type ?? ''));
        $attackerMatches = (int) $attack->attacker_nation_id === $participantId;
        if (! in_array($type, ['VICTORY', 'ALLIANCELOOT'], true)) {
            return $attackerMatches;
        }

        $victor = (int) ($attack->victor ?? 0);

        return $victor > 0 ? $victor === $participantId : $attackerMatches;
    }

    /** @param iterable<RaidOutcomeAttack> $attacks @return array<string, float> */
    private function sumCosts(iterable $attacks, int $participantId): array
    {
        $costs = array_fill_keys(self::COST_RESOURCES, 0.0);
        foreach ($attacks as $attack) {
            $isAttacker = (int) $attack->attacker_nation_id === $participantId;
            $source = $isAttacker ? $attack->cost_resources : $attack->defender_cost_resources;
            foreach ((array) ($source ?? []) as $resource => $amount) {
                if (is_numeric($amount)) {
                    $costs[(string) $resource] = ($costs[(string) $resource] ?? 0.0) + (float) $amount;
                }
            }
        }

        return $this->roundMap($costs);
    }

    /** @param iterable<RaidOutcomeAttack> $attacks @return array<string, float> */
    private function sumLosses(iterable $attacks, int $participantId): array
    {
        $losses = [
            'soldiers' => 0.0,
            'tanks' => 0.0,
            'aircraft' => 0.0,
            'ships' => 0.0,
            'missiles' => 0.0,
            'nukes' => 0.0,
            'infrastructure_value' => 0.0,
            'infrastructure_unvalued' => 0.0,
        ];

        foreach ($attacks as $attack) {
            $isAttacker = (int) $attack->attacker_nation_id === $participantId;
            $casualties = $isAttacker ? $attack->casualties : $attack->defender_casualties;
            $infrastructure = $isAttacker ? $attack->attacker_infrastructure : $attack->defender_infrastructure;
            foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'] as $unit) {
                $losses[$unit] += (float) (($casualties ?? [])[$unit] ?? 0);
            }
            $losses['infrastructure_value'] += (float) (($infrastructure ?? [])['destroyed_value'] ?? 0);
            if ((float) (($infrastructure ?? [])['destroyed'] ?? 0) > 0
                && ! array_key_exists('destroyed_value', (array) ($infrastructure ?? []))) {
                $losses['infrastructure_unvalued'] += (float) (($infrastructure ?? [])['destroyed'] ?? 0);
            }
        }

        return $this->roundMap($losses);
    }

    /**
     * War aggregates are the authoritative cumulative source for strategic
     * weapons because the normal attack payload does not consistently expose
     * missile and nuke expenditure. Per-attack evidence remains the fallback
     * for retained or corrected rows whose aggregate is unavailable.
     *
     * @param  array<string, float>  $losses
     * @return array<string, float>
     */
    private function applyStrategicUnitUsage(array $losses, ?War $war, int $participantId): array
    {
        if ($war === null) {
            return $losses;
        }

        $prefix = (int) $war->getAttribute('att_id') === $participantId ? 'att_' : (
            (int) $war->getAttribute('def_id') === $participantId ? 'def_' : null
        );
        if ($prefix === null) {
            return $losses;
        }

        foreach (['missiles', 'nukes'] as $unit) {
            $aggregate = $war->getAttribute($prefix.$unit.'_used');
            if (is_numeric($aggregate)) {
                $losses[$unit] = max((float) ($losses[$unit] ?? 0.0), (float) $aggregate);
            }
        }

        return $this->roundMap($losses);
    }

    /** @param array<string, float> $losses */
    private function hasPositiveMilitaryLosses(array $losses): bool
    {
        foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'] as $unit) {
            if ((float) ($losses[$unit] ?? 0.0) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, float> $resources @param array<string, float> $prices */
    private function valueLoot(array $resources, array $prices): ?float
    {
        $value = (float) ($resources['money'] ?? 0);
        foreach ($resources as $resource => $amount) {
            if ((float) $amount === 0.0 || $resource === 'money') {
                continue;
            }

            if (! isset($prices[$resource]) || $prices[$resource] <= 0) {
                return null;
            }

            $value += (float) $amount * (float) $prices[$resource];
        }

        return $value;
    }

    /** @param array<string, float> $resources @param array<string, float> $prices */
    private function valueResources(array $resources, array $prices): ?float
    {
        $value = 0.0;
        foreach ($resources as $resource => $amount) {
            if ((float) $amount === 0.0) {
                continue;
            }

            if (! isset($prices[$resource]) || $prices[$resource] <= 0) {
                return null;
            }

            $value += $amount * (float) ($prices[$resource] ?? 0);
        }

        return $value;
    }

    /** @param array<string, float> $losses @param array<string, float> $prices */
    private function valueMilitaryLosses(array $losses, RaidPrediction $prediction, array $prices): ?float
    {
        $quantities = [];
        foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'] as $unit) {
            $quantities[$unit] = max(0, (int) round((float) ($losses[$unit] ?? 0)));
        }
        if (array_sum($quantities) === 0) {
            return 0.0;
        }

        $attacker = is_array($prediction->attacker_snapshot) ? $prediction->attacker_snapshot : [];
        $research = $attacker['military_research'] ?? $attacker['research'] ?? [];
        $research = is_array($research) ? $research : [];
        $policy = strtoupper((string) ($attacker['domestic_policy'] ?? ''));
        $priceSet = new MarketPriceSet(
            acquisitionPrices: $prices,
            liquidationPrices: $prices,
        );

        try {
            $result = $this->militaryCostCalculator->calculate(
                quantities: $quantities,
                researchLevels: $research,
                wartime: true,
                imperialism: $policy === 'IMPERIALISM',
                governmentSupportAgency: (bool) ($attacker['government_support_agency'] ?? false),
                bureauOfDomesticAffairs: (bool) ($attacker['bureau_of_domestic_affairs'] ?? false),
                prices: $priceSet,
            );

            return $result->breakdowns['purchase']->marketValue;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, float> $losses */
    private function infrastructureLossValue(array $losses): ?float
    {
        if ((float) ($losses['infrastructure_unvalued'] ?? 0.0) > 0.0) {
            return null;
        }

        return (float) ($losses['infrastructure_value'] ?? 0.0);
    }

    /** @param array<string, mixed> $values @return array<string, float> */
    private function roundMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_numeric($value)) {
                $result[(string) $key] = round((float) $value, 4);
            }
        }

        return $result;
    }

    private function roundNullable(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }

    /** @param array<string, mixed>|null $expected @param array<string, mixed>|null $actual @return array<string, float> */
    private function resourceError(?array $expected, ?array $actual): array
    {
        $expected ??= [];
        $actual ??= [];
        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        $errors = [];
        foreach ($keys as $key) {
            if (is_numeric($expected[$key] ?? null) && is_numeric($actual[$key] ?? null)) {
                $errors[(string) $key] = round((float) $actual[$key] - (float) $expected[$key], 4);
            }
        }

        return $errors;
    }

    /** @param Collection<int, RaidOutcomeAttack> $attacks @return array<string, mixed> */
    private function planAdherence(RaidPrediction $prediction, Collection $attacks): array
    {
        $actions = data_get($prediction->context_snapshot, 'plan');
        if (! is_array($actions) || $actions === []) {
            return [
                'status' => 'unavailable',
                'score' => null,
                'expected_actions' => [],
                'observed_actions' => [],
                'matched_actions' => 0,
            ];
        }

        $expected = array_values(array_filter(array_map(
            fn (mixed $action): ?string => is_string($action) ? $this->actionType($action) : null,
            $actions,
        )));
        $observed = $attacks->map(fn (RaidOutcomeAttack $attack): ?string => $this->actionType($attack->attack_type))->filter()->values()->all();
        if ($expected === []) {
            return [
                'status' => 'unavailable',
                'score' => null,
                'expected_actions' => [],
                'observed_actions' => $observed,
                'matched_actions' => 0,
            ];
        }

        $matched = 0;
        $cursor = 0;
        foreach ($observed as $actual) {
            while (isset($expected[$cursor])) {
                if ($expected[$cursor] === $actual) {
                    $matched++;
                    $cursor++;
                    break;
                }
                $cursor++;
            }
        }

        $denominator = max(count($expected), count($observed), 1);

        return [
            'status' => 'observed',
            'score' => round($matched / $denominator, 4),
            'expected_actions' => $expected,
            'observed_actions' => $observed,
            'matched_actions' => $matched,
        ];
    }

    private function actionType(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        return $value === null || $value === '' ? null : strtolower((string) $value);
    }

    private function outcomeStatus(?War $war, int $attackerId): string
    {
        if ($war === null) {
            return RaidPrediction::OUTCOME_OPEN;
        }

        $winner = (int) ($war->getAttribute('winner_id') ?? 0);
        if ($winner === $attackerId) {
            return RaidPrediction::OUTCOME_WON;
        }

        if ($winner > 0) {
            return RaidPrediction::OUTCOME_LOST;
        }

        if ((bool) $war->getAttribute('att_peace') && (bool) $war->getAttribute('def_peace')) {
            return RaidPrediction::OUTCOME_PEACE;
        }

        if ($war->getAttribute('end_date') !== null
            || (is_numeric($war->getAttribute('turns_left')) && (int) $war->getAttribute('turns_left') <= 0)) {
            return RaidPrediction::OUTCOME_EXPIRED;
        }

        return RaidPrediction::OUTCOME_OPEN;
    }

    private function warIsTerminal(?War $war): bool
    {
        if ($war === null) {
            return false;
        }

        return $this->outcomeStatus($war, (int) $war->getAttribute('att_id')) !== RaidPrediction::OUTCOME_OPEN;
    }

    private function durationHours(
        RaidPrediction $prediction,
        ?War $war,
        bool $terminal,
        ?Collection $evidence = null,
    ): ?float {
        if (! $terminal) {
            return null;
        }

        // The war's terminal timestamp is the game event time. A queued
        // reconciliation can run later, so never derive duration from the
        // local finalization timestamp. If a terminal war row is pruned,
        // retain a previously recorded game timestamp when available.
        if ($war?->getAttribute('end_date') === null && $prediction->actual_duration_hours !== null) {
            return (float) $prediction->actual_duration_hours;
        }

        $end = $war?->getAttribute('end_date');
        if ($end === null && $war !== null && $evidence !== null) {
            $winner = (int) ($war->getAttribute('winner_id') ?? 0);
            $end = $evidence
                ->filter(function (RaidOutcomeAttack $attack) use ($winner): bool {
                    return strtoupper((string) $attack->attack_type) === 'VICTORY'
                        && $winner > 0
                        && (int) ($attack->victor ?? 0) === $winner
                        && $attack->attack_at !== null;
                })
                ->sortByDesc('attack_at')
                ->first()
                ?->attack_at;
        }
        if ($end === null) {
            return null;
        }

        try {
            $endAt = $end instanceof CarbonInterface ? CarbonImmutable::instance($end) : CarbonImmutable::parse((string) $end);

            return round($prediction->declared_at->diffInSeconds($endAt) / 3600, 4);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param Collection<int, RaidOutcomeAttack> $evidence */
    private function durationBasis(?War $war, Collection $evidence, ?float $actualDuration): string
    {
        if ($actualDuration === null) {
            return 'unavailable';
        }

        if ($war?->getAttribute('end_date') !== null) {
            return 'war_end_date';
        }

        $winner = (int) ($war?->getAttribute('winner_id') ?? 0);
        $hasVictoryTimestamp = $winner > 0 && $evidence->contains(
            fn (RaidOutcomeAttack $attack): bool => strtoupper((string) $attack->attack_type) === 'VICTORY'
                && (int) ($attack->victor ?? 0) === $winner
                && $attack->attack_at !== null,
        );

        if ($hasVictoryTimestamp) {
            return 'victory_attack_at';
        }

        return $war === null ? 'preserved' : 'previously_recorded';
    }

    /**
     * Normalize the two game loot attack types into separate buckets. The
     * alliance-loot attack reuses the victory fields, so its resource columns
     * must never also be treated as nation loot.
     *
     * @return array{nation: array<string, float>, bank: array<string, float>, money_looted: float}
     */
    private function canonicalLoot(array $payload, ?string $attackType): array
    {
        $nation = $this->resourceMap($payload['loot_resources'] ?? null);
        foreach (self::LOOT_RESOURCES as $resource) {
            $key = $resource.'_looted';
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $nation[$resource] = (float) $payload[$key];
            }
        }

        $moneyLooted = $this->number($payload['money_looted'] ?? 0);
        unset($nation['money']);
        $bank = $this->bankLoot($payload);

        if (strtoupper((string) $attackType) !== 'ALLIANCELOOT') {
            return [
                'nation' => $nation,
                'bank' => $bank,
                'money_looted' => $moneyLooted,
            ];
        }

        // GraphQL ALLIANCELOOT exposes the same *_looted columns as VICTORY.
        // Those columns are the canonical bank amount. Use explicit bank
        // metadata only when a payload contains no raw loot fields at all.
        if ($this->hasRawLootFields($payload)) {
            if (array_key_exists('money_looted', $payload)) {
                $nation['money'] = $moneyLooted;
            }
            $bank = $this->roundMap($nation);
        }

        return [
            'nation' => [],
            'bank' => $bank,
            'money_looted' => 0.0,
        ];
    }

    private function hasRawLootFields(array $payload): bool
    {
        if (array_key_exists('money_looted', $payload)) {
            return true;
        }

        if (is_array($payload['loot_resources'] ?? null) && $payload['loot_resources'] !== []) {
            return true;
        }

        foreach (self::LOOT_RESOURCES as $resource) {
            if (array_key_exists($resource.'_looted', $payload)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizePayload(array $payload): array
    {
        $attackType = $this->stringOrNull($payload['type'] ?? $payload['attack_type'] ?? null);
        $canonicalLoot = $this->canonicalLoot($payload, $attackType);
        $loot = $canonicalLoot['nation'];
        $bankLoot = $canonicalLoot['bank'];
        $moneyLooted = $canonicalLoot['money_looted'];

        $costs = $this->resourceMap($payload['cost_resources'] ?? null);
        $defenderCosts = $this->resourceMap($payload['defender_cost_resources'] ?? null);
        $costKeys = [
            'gasoline' => ['att_gas_used', 'gasoline_used', 'gas_used'],
            'munitions' => ['att_mun_used', 'munitions_used', 'mun_used'],
            'aluminum' => ['att_alum_used', 'att_aluminum_used', 'aluminum_used'],
            'steel' => ['att_steel_used', 'steel_used'],
        ];
        foreach ($costKeys as $resource => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                    $costs[$resource] = (float) $payload[$key];
                    break;
                }
            }
        }
        foreach ($costKeys as $resource => $keys) {
            $defenderKeys = array_map(
                static fn (string $key): string => str_starts_with($key, 'att_') ? 'def_'.substr($key, 4) : 'def_'.$key,
                $keys,
            );
            foreach ($defenderKeys as $key) {
                if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                    $defenderCosts[$resource] = (float) $payload[$key];
                    break;
                }
            }
        }

        $casualties = $this->numberMap($payload['casualties'] ?? null);
        $defenderCasualties = $this->numberMap($payload['defender_casualties'] ?? null);
        foreach (['soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes'] as $unit) {
            $key = 'att_'.$unit.'_lost';
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $casualties[$unit] = (float) $payload[$key];
            }
            $key = 'def_'.$unit.'_lost';
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $defenderCasualties[$unit] = (float) $payload[$key];
            }
        }

        $infrastructure = $this->numberMap($payload['infrastructure'] ?? null);
        $attackerInfrastructure = $this->numberMap($payload['attacker_infrastructure'] ?? null);
        $defenderInfrastructure = $this->numberMap($payload['defender_infrastructure'] ?? null);
        foreach (['infra_destroyed', 'infra_destroyed_value'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $infrastructure[$key === 'infra_destroyed' ? 'destroyed' : 'destroyed_value'] = (float) $payload[$key];
                $defenderInfrastructure[$key === 'infra_destroyed' ? 'destroyed' : 'destroyed_value'] = (float) $payload[$key];
            }
        }
        foreach (['att_infra_destroyed', 'att_infra_destroyed_value'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $attackerInfrastructure[str_starts_with($key, 'att_') ? substr($key, 4) : $key] = (float) $payload[$key];
            }
        }
        foreach (['def_infra_destroyed', 'def_infra_destroyed_value'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                $defenderInfrastructure[str_starts_with($key, 'def_') ? substr($key, 4) : $key] = (float) $payload[$key];
            }
        }

        $attackAt = $this->timestamp($payload['date'] ?? $payload['attack_at'] ?? null);
        $hashPayload = [
            'attacker_nation_id' => $this->positiveInteger($payload['att_id'] ?? $payload['attacker_nation_id'] ?? $payload['attacker_id'] ?? null),
            'defender_nation_id' => $this->positiveInteger($payload['def_id'] ?? $payload['defender_nation_id'] ?? $payload['defender_id'] ?? null),
            'attack_at' => $attackAt?->toIso8601String(),
            'attack_type' => $attackType,
            'victor' => $this->positiveInteger($payload['victor'] ?? null),
            'success' => is_numeric($payload['success'] ?? null) ? (int) $payload['success'] : null,
            'money_looted' => $moneyLooted,
            'money_stolen' => $this->number($payload['money_stolen'] ?? 0),
            'money_destroyed' => $this->number($payload['money_destroyed'] ?? 0),
            'loot_resources' => $loot,
            'bank_loot' => $bankLoot,
            'cost_resources' => $costs,
            'defender_cost_resources' => $defenderCosts,
            'casualties' => $casualties,
            'defender_casualties' => $defenderCasualties,
            'infrastructure' => $infrastructure,
            'attacker_infrastructure' => $attackerInfrastructure,
            'defender_infrastructure' => $defenderInfrastructure,
            'bounty' => $this->explicitBounty($payload),
        ];

        return [
            'attacker_nation_id' => $hashPayload['attacker_nation_id'],
            'defender_nation_id' => $hashPayload['defender_nation_id'],
            'attack_at' => $attackAt,
            'attack_type' => $attackType,
            'victor' => $hashPayload['victor'],
            'success' => $hashPayload['success'],
            'money_looted' => $hashPayload['money_looted'],
            'money_stolen' => $hashPayload['money_stolen'],
            'money_destroyed' => $hashPayload['money_destroyed'],
            'loot_resources' => $loot,
            'bank_loot' => $bankLoot,
            'cost_resources' => $costs,
            'defender_cost_resources' => $defenderCosts,
            'casualties' => $casualties,
            'defender_casualties' => $defenderCasualties,
            'infrastructure' => $infrastructure,
            'attacker_infrastructure' => $attackerInfrastructure,
            'defender_infrastructure' => $defenderInfrastructure,
            'payload_hash' => hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param mixed $value @return array<string, float> */
    private function resourceMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $resource => $amount) {
            if (is_numeric($amount)) {
                $result[(string) $resource] = (float) $amount;
            }
        }

        return $result;
    }

    /** @param mixed $value @return array<string, float> */
    private function numberMap(mixed $value): array
    {
        return $this->resourceMap($value);
    }

    /** @param array<string, mixed> $payload @return array<string, float> */
    private function bankLoot(array $payload): array
    {
        $candidates = [
            $payload['bank_loot'] ?? null,
            $payload['alliance_bank_loot'] ?? null,
            $payload['bank_loot_resources'] ?? null,
        ];
        $lootInfo = $payload['loot_info'] ?? null;
        if (is_string($lootInfo) && $lootInfo !== '') {
            $decoded = json_decode($lootInfo, true);
            if (is_array($decoded)) {
                $candidates[] = $decoded['bank'] ?? null;
                $candidates[] = $decoded['bank_loot'] ?? null;
                $candidates[] = $decoded['alliance_bank'] ?? null;
            }
        }

        $result = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $decoded = json_decode($candidate, true);
                $candidate = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($candidate)) {
                continue;
            }
            $candidate = $candidate['resources'] ?? $candidate;
            $result = array_merge($result, $this->resourceMap($candidate));
        }

        foreach (array_merge(self::LOOT_RESOURCES, ['money']) as $resource) {
            $aliases = [
                'bank_'.$resource,
                'bank_'.$resource.'_looted',
                'alliance_bank_'.$resource,
                'alliance_bank_'.$resource.'_looted',
            ];
            if ($resource === 'money') {
                $aliases[] = 'bank_cash';
                $aliases[] = 'alliance_bank_cash';
            }
            foreach ($aliases as $key) {
                if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                    $result[$resource] = (float) $payload[$key];
                    break;
                }
            }
        }

        return $this->roundMap($result);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
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

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function matchesWarParticipants(
        ?int $payloadAttackerId,
        ?int $payloadDefenderId,
        ?int $expectedAttackerId,
        ?int $expectedDefenderId,
    ): bool {
        if ($payloadAttackerId === null || $payloadDefenderId === null
            || $expectedAttackerId === null || $expectedDefenderId === null) {
            return false;
        }

        return ($payloadAttackerId === $expectedAttackerId && $payloadDefenderId === $expectedDefenderId)
            || ($payloadAttackerId === $expectedDefenderId && $payloadDefenderId === $expectedAttackerId);
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function canWrite(): bool
    {
        return ($this->runtimeCapabilities ?? app(RuntimeCapabilities::class))->writesTenantPrivate();
    }
}
