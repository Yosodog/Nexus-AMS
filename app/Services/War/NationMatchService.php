<?php

namespace App\Services\War;

use App\Models\Nation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Generates match scores between friendly and enemy nations to drive assignments.
 *
 * Design Notes:
 * - The service is intentionally stateless so controllers/services can memoize or parallelise usage.
 * - Every factor returns both numeric contribution and rationale text for UI tooltips.
 * - We prefer smooth curves (logistic/ratio) over hard thresholds so manual overrides remain intuitive.
 */
class NationMatchService
{
    /**
     * Evaluate a match score for a friendly/enemy pairing.
     *
     * @param array{
     *     available_slots?:int,
     *     assignment_load?:int,
     *     max_assignments?:int,
     *     cohesion_reference?:float,
     *     cohesion_tolerance?:int,
     *     enemy_tps?:float,
     *     evaluation_mode?:string,
     *     friendly_strength_rank?:float,
     *     enemy_threat_rank?:float,
     *     activity_window_hours?:int
     * } $context
     * @return array{score: float, meta: array<string, mixed>}
     */
    public function evaluate(Nation $friendly, Nation $enemy, array $context = []): array
    {
        $weights = config('war.nation_match.weights', []);
        $mode = $context['evaluation_mode'] ?? 'auto';
        $friendlyStrengthRank = max(0.0, (float) ($context['friendly_strength_rank'] ?? 0.0));
        $enemyThreatRank = max(0.0, (float) ($context['enemy_threat_rank'] ?? 0.0));
        $activityWindowHours = (int) ($context['activity_window_hours'] ?? config('war.plan_defaults.activity_window_hours', 72));

        $mmrCompliance = $this->mmrCompliance($friendly);
        $meta = [
            'factors' => [],
            'weights' => $weights,
            'mode' => $mode,
        ];

        $score = 0.0;

        $availability = $this->availabilityFactor($friendly, (int) ($context['available_slots'] ?? 0));
        $availabilityEntry = $this->factorEntry('availability', $availability['value'], $weights, $availability['reason']);
        $score += $availabilityEntry['impact'];
        $meta['factors']['availability'] = $availabilityEntry;

        $military = $this->militaryEffectiveness($friendly);
        $militaryEntry = $this->factorEntry('military_effectiveness', $military['value'], $weights, $military['reason']);
        $score += $militaryEntry['impact'];
        $meta['factors']['military_effectiveness'] = $militaryEntry;

        $cityAdvantage = $this->cityAdvantage($friendly->num_cities ?? 0, $enemy->num_cities ?? 0);
        $cityEntry = $this->factorEntry('city_advantage', $cityAdvantage['value'], $weights, $cityAdvantage['reason']);
        $score += $cityEntry['impact'];
        $meta['factors']['city_advantage'] = $cityEntry;

        // Rationale: cap outgoing score by parity so mismatched pairings never look attractive.
        $relativePower = $this->relativePowerProfile($friendly, $enemy, $mode);
        $relativeEntry = $this->factorEntry(
            'relative_power',
            $relativePower['value'],
            $weights,
            $relativePower['reason'] ?? null,
            null,
            [
                'details' => $relativePower['details'] ?? [],
            ]
        );
        $score += $relativeEntry['impact'];
        $meta['factors']['relative_power'] = $relativeEntry;

        $recentActivity = $this->recentActivityFactor($friendly->accountProfile?->last_active, $activityWindowHours);
        $recentEntry = $this->factorEntry('recent_activity', $recentActivity['value'], $weights, $recentActivity['reason']);
        $score += $recentEntry['impact'];
        $meta['factors']['recent_activity'] = $recentEntry;

        $assignmentLoad = $this->assignmentLoadPenalty(
            (int) ($context['assignment_load'] ?? 0),
            (int) ($context['max_assignments'] ?? 3)
        );
        $assignmentEntry = $this->factorEntry('assignment_load_penalty', $assignmentLoad['value'], $weights, $assignmentLoad['reason']);
        $score += $assignmentEntry['impact'];
        $meta['factors']['assignment_load_penalty'] = $assignmentEntry;

        $mmrEntry = $this->factorEntry('mmr_compliance', $mmrCompliance['value'], $weights, $mmrCompliance['reason']);
        $score += $mmrEntry['impact'];
        $meta['factors']['mmr_compliance'] = $mmrEntry;

        $cohesion = $this->cohesionBonus(
            $recentActivity['value'],
            (float) ($context['cohesion_reference'] ?? 0.5),
            (int) ($context['cohesion_tolerance'] ?? config('war.squads.cohesion_tolerance', 10))
        );
        $cohesionEntry = $this->factorEntry('cohesion_bonus', $cohesion['value'], $weights, $cohesion['reason']);
        $score += $cohesionEntry['impact'];
        $meta['factors']['cohesion_bonus'] = $cohesionEntry;

        $colorPenalty = $this->colorPenalty($friendly->color ?? '', $enemy->color ?? '');
        $colorEntry = $this->factorEntry('color_penalty', $colorPenalty['value'], $weights, $colorPenalty['reason']);
        $score += $colorEntry['impact'];
        $meta['factors']['color_penalty'] = $colorEntry;

        $enemyTps = (float) ($context['enemy_tps'] ?? 50);
        $tpsBiasValue = $this->normalize($enemyTps, 100);
        $relativeMultiplier = max(0, $relativePower['value']);
        $tpsImpact = ($weights['tps_bias'] ?? 0) * $tpsBiasValue * 100 * $relativeMultiplier;
        $tpsEntry = $this->factorEntry(
            'tps_bias',
            $tpsBiasValue,
            $weights,
            sprintf('Enemy TPS %.1f scaled by relative parity %.2f.', $enemyTps, $relativeMultiplier),
            $tpsImpact,
            ['relative_power_multiplier' => $relativeMultiplier]
        );
        $score += $tpsEntry['impact'];
        $meta['factors']['tps_bias'] = $tpsEntry;

        $pairFit = $this->pairingBalance($friendlyStrengthRank, $enemyThreatRank);
        $pairEntry = $this->factorEntry('strong_vs_strong', $pairFit['value'], $weights, $pairFit['reason']);
        $score += $pairEntry['impact'];
        $meta['factors']['strong_vs_strong'] = $pairEntry;

        $dominance = $this->dominanceBonus($friendlyStrengthRank, $enemyThreatRank);
        $dominanceEntry = $this->factorEntry('dominance', $dominance['value'], $weights, $dominance['reason']);
        $score += $dominanceEntry['impact'];
        $meta['factors']['dominance'] = $dominanceEntry;

        $beigePenalty = $this->friendlyBeigePenalty($friendly);
        $beigeEntry = $this->factorEntry('friendly_beige_penalty', $beigePenalty['value'], $weights, $beigePenalty['reason']);
        $score += $beigeEntry['impact'];
        $meta['factors']['friendly_beige_penalty'] = $beigeEntry;

        $preCap = max(0, min(100, $score));
        $cap = $relativePower['cap'] ?? 100;
        $bounded = min($preCap, $cap);

        $meta['raw_score'] = round($score, 2);
        $meta['pre_cap'] = round($preCap, 2);
        $meta['caps'] = [
            'relative_power' => $cap,
            'mode' => $mode,
        ];
        $meta['bounded'] = round($bounded, 2);
        $meta['relative_power_details'] = $relativePower['details'] ?? [];
        $meta['rank_context'] = [
            'friendly_strength_rank' => $friendlyStrengthRank,
            'enemy_threat_rank' => $enemyThreatRank,
        ];

        return [
            'score' => round($bounded, 2),
            'meta' => $meta,
        ];
    }

    /**
     * Availability is binary but returns 1 when slots are open to keep weight scaling intuitive.
     */
    protected function availabilityFactor(Nation $friendly, int $availableSlots): array
    {
        $value = $availableSlots > 0 ? 1.0 : 0.0;
        $reason = $availableSlots > 0
            ? sprintf('%d offensive slot(s) available after current wars.', $availableSlots)
            : 'All offensive slots are currently occupied.';

        return [
            'value' => $value,
            'reason' => $reason,
        ];
    }

    /**
     * Blend offensive units to approximate readiness.
     */
    protected function militaryEffectiveness(Nation $friendly): array
    {
        $military = $friendly->military;

        if (! $military) {
            return [
                'value' => 0.35,
                'reason' => 'No military snapshot available; falling back to conservative baseline.',
            ];
        }

        $weights = [
            'soldiers' => 0.2,
            'tanks' => 0.25,
            'aircraft' => 0.3,
            'ships' => 0.15,
            'missiles' => 0.05,
            'nukes' => 0.05,
        ];

        $totals = [
            'soldiers' => $military->soldiers ?? 0,
            'tanks' => $military->tanks ?? 0,
            'aircraft' => $military->aircraft ?? 0,
            'ships' => $military->ships ?? 0,
            'missiles' => $military->missiles ?? 0,
            'nukes' => $military->nukes ?? 0,
        ];

        $score = 0.0;
        foreach ($weights as $unit => $weight) {
            $cap = match ($unit) {
                'soldiers' => 15_000 * max(1, $friendly->num_cities),
                'tanks' => 1_250 * max(1, $friendly->num_cities),
                'aircraft' => 75 * max(1, $friendly->num_cities),
                'ships' => 15 * max(1, $friendly->num_cities),
                default => 10,
            };
            $score += $weight * $this->normalize($totals[$unit], $cap);
        }

        return [
            'value' => round(min(1, $score), 4),
            'reason' => 'Weighted readiness across offensive units.',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function cityAdvantage(int $friendlyCities, int $enemyCities): array
    {
        $delta = $friendlyCities - $enemyCities;

        $coefficient = 0.04; // Rationale: every ~5 city advantage adds ~0.2
        $score = 0.5 + tanh($delta * $coefficient) / 2;

        $value = round(max(0, min(1, $score)), 4);

        return [
            'value' => $value,
            'reason' => sprintf(
                'City differential %d vs %d (Δ %d) mapped through logistic curve.',
                $friendlyCities,
                $enemyCities,
                $delta
            ),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function recentActivityFactor(?CarbonInterface $lastSeen, ?int $activityWindowHours = null): array
    {
        $window = $activityWindowHours ?? config('war.plan_defaults.activity_window_hours', 72);
        $halfLife = max(1, $window / 2);
        $maxHours = max($window * 2, $window + 12);
        $assumed = ! $lastSeen;
        $hoursAgo = $lastSeen ? $lastSeen->diffInHours(Carbon::now()) : ($window + 1);
        $hoursAgo = min($maxHours, max(0, $hoursAgo));

        $factor = pow(0.5, $hoursAgo / $halfLife);

        return [
            'value' => round($factor, 4),
            'reason' => sprintf(
                $assumed
                    ? 'No recent login captured; assuming %d hour(s) ago with %.1f hour half-life (window %dh).'
                    : 'Last active %d hour(s) ago with %.1f hour half-life (window %dh).',
                $hoursAgo,
                $halfLife,
                $window
            ),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function assignmentLoadPenalty(int $currentAssignments, int $maxAssignments): array
    {
        if ($maxAssignments <= 0) {
            return [
                'value' => 1.0,
                'reason' => 'Max assignments is zero; treating as fully saturated.',
            ];
        }

        $ratio = min(1, $currentAssignments / $maxAssignments);

        return [
            'value' => round($ratio, 4),
            'reason' => sprintf('Assignments %d of %d allowed.', $currentAssignments, $maxAssignments),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function mmrCompliance(Nation $friendly): array
    {
        $mmrScore = $friendly->latestSignIn?->mmr_score;

        if ($mmrScore === null) {
            return [
                'value' => 0.5,
                'reason' => 'No MMR history on record; neutral baseline 0.5 applied.',
            ];
        }

        return [
            'value' => round(min(1, max(0, $mmrScore / 100)), 4),
            'reason' => sprintf('MMR score %.1f / 100 normalised to [0,1].', $mmrScore),
        ];
    }

    protected function cohesionBonus(
        float $recentActivityFactor,
        float $reference,
        int $tolerance
    ): array {
        $delta = abs($recentActivityFactor - $reference);

        $scaledTolerance = max(0.1, $tolerance / 100);

        $score = max(0, 1 - ($delta / $scaledTolerance));

        return [
            'value' => round($score, 4),
            'reason' => sprintf(
                'Readiness delta %.2f vs reference %.2f with ±%d%% tolerance.',
                $delta,
                $reference,
                $tolerance
            ),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function colorPenalty(string $friendlyColor, string $enemyColor): array
    {
        return [
            'value' => 0.5,
            'reason' => 'Colour parity ignored for targeting; beige/vacation status drives priority.',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function pairingBalance(float $friendlyRank, float $enemyRank): array
    {
        if ($friendlyRank <= 0 && $enemyRank <= 0) {
            return [
                'value' => 0.0,
                'reason' => 'No strength/threat ranks available; skipping pair-fit bonus.',
            ];
        }

        $fit = max(0, 1 - abs($friendlyRank - $enemyRank));

        return [
            'value' => round($fit, 4),
            'reason' => sprintf(
                'Pair fit between friendly %.2f and enemy %.2f (closer alignment is better).',
                $friendlyRank,
                $enemyRank
            ),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function dominanceBonus(float $friendlyRank, float $enemyRank): array
    {
        $delta = max(0, $friendlyRank - $enemyRank);

        return [
            'value' => round($delta, 4),
            'reason' => sprintf(
                'Dominance delta %.2f (friendly %.2f minus threat %.2f).',
                $delta,
                $friendlyRank,
                $enemyRank
            ),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function friendlyBeigePenalty(Nation $friendly): array
    {
        $beigeTurns = (int) ($friendly->beige_turns ?? 0);

        return [
            'value' => $beigeTurns > 0 ? 1.0 : 0.0,
            'reason' => $beigeTurns > 0
                ? sprintf('Friendly nation is on beige for %d turn(s); prefer alternatives.', $beigeTurns)
                : 'Friendly nation is not on beige.',
        ];
    }

    /**
     * Compare city scale, nation score, and effective military power to gate the final score.
     *
     * @return array{
     *     value: float,
     *     cap: float,
     *     details: array<string, mixed>
     * }
     */
    protected function relativePowerProfile(Nation $friendly, Nation $enemy, string $mode = 'auto'): array
    {
        $config = config('war.nation_match.relative_power', []);
        $floor = $mode === 'manual'
            ? ($config['manual_ratio_floor'] ?? 0.38)
            : ($config['auto_ratio_floor'] ?? 0.48);
        $ceiling = $config['ratio_ceiling'] ?? 0.95;
        $exponent = $mode === 'manual'
            ? ($config['manual_curve_exponent'] ?? 0.75)
            : ($config['auto_curve_exponent'] ?? 0.85);
        $minCap = $mode === 'manual'
            ? ($config['manual_min_cap'] ?? 40)
            : ($config['auto_min_cap'] ?? 24);

        $cityRatio = $this->symmetricalRatio($friendly->num_cities ?? 0, $enemy->num_cities ?? 0);
        $scoreRatio = $this->symmetricalRatio($friendly->score ?? 0.0, $enemy->score ?? 0.0);
        $strengthRatio = $this->strengthRatio($friendly, $enemy);

        $ratios = array_filter([
            'city' => $cityRatio,
            'score' => $scoreRatio,
            'strength' => $strengthRatio,
        ], static fn ($value) => $value !== null);

        $minRatio = empty($ratios) ? 1.0 : min($ratios);
        $parity = $this->mapRatioToParity($minRatio, $floor, $ceiling, $exponent);

        $components = [
            sprintf('cities %.2f', $cityRatio),
            sprintf('score %.2f', $scoreRatio),
            sprintf('strength %s', $strengthRatio !== null ? number_format($strengthRatio, 2) : 'n/a'),
        ];

        $reason = sprintf(
            'Minimum parity ratio %.2f across %s (mode: %s).',
            $minRatio,
            implode(', ', $components),
            $mode
        );

        return [
            'value' => $parity,
            'cap' => max($minCap, round($parity * 100, 2)),
            'details' => [
                'city_ratio' => $cityRatio,
                'score_ratio' => $scoreRatio,
                'strength_ratio' => $strengthRatio,
                'min_ratio' => $minRatio,
                'mode' => $mode,
            ],
            'reason' => $reason,
        ];
    }

    /**
     * Envelope min/max ratio to a 0–1 parity curve with mode-specific tuning.
     */
    protected function mapRatioToParity(float $ratio, float $floor, float $ceiling, float $exponent): float
    {
        if ($ratio <= $floor) {
            return 0.0;
        }

        if ($ratio >= $ceiling) {
            return 1.0;
        }

        $range = max(0.01, $ceiling - $floor);
        $normalized = ($ratio - $floor) / $range;

        $curved = pow(max(0.0, min(1.0, $normalized)), max(0.1, $exponent));

        return round(max(0.0, min(1.0, $curved)), 4);
    }

    /**
     * Symmetrical ratio that emphasises the smaller nation by dividing lower by higher.
     */
    protected function symmetricalRatio(float|int|null $a, float|int|null $b): float
    {
        $left = (float) ($a ?? 0);
        $right = (float) ($b ?? 0);

        if ($left <= 0 && $right <= 0) {
            return 1.0;
        }

        if ($left <= 0 || $right <= 0) {
            return 0.0;
        }

        $min = min($left, $right);
        $max = max($left, $right);

        return $max <= 0 ? 0.0 : round($min / $max, 4);
    }

    /**
     * Derive a strength ratio using offensive units; null indicates missing intel.
     */
    protected function strengthRatio(Nation $friendly, Nation $enemy): ?float
    {
        $friendlyStrength = $this->combatStrength($friendly);
        $enemyStrength = $this->combatStrength($enemy);

        if ($friendlyStrength === null || $enemyStrength === null) {
            return null;
        }

        if ($enemyStrength <= 0.0 && $friendlyStrength <= 0.0) {
            return 1.0;
        }

        if ($enemyStrength <= 0.0 || $friendlyStrength <= 0.0) {
            return 0.0;
        }

        $min = min($friendlyStrength, $enemyStrength);
        $max = max($friendlyStrength, $enemyStrength);

        return round($min / $max, 4);
    }

    /**
     * Estimate aggregate offensive capacity for parity checks.
     */
    protected function combatStrength(Nation $nation): ?float
    {
        $military = $nation->military;

        if (! $military) {
            // If we lack military intel, fall back to city scale × readiness proxy.
            if (($nation->num_cities ?? 0) <= 0) {
                return null;
            }

            return max(1.0, ($nation->num_cities ?? 1) * max(1.0, $nation->score ?? 1));
        }

        $weights = [
            'soldiers' => 1,
            'tanks' => 12,
            'aircraft' => 28,
            'ships' => 36,
            'missiles' => 200,
            'nukes' => 500,
        ];

        $strength = 0.0;
        $strength += (($military->soldiers ?? 0) * $weights['soldiers']);
        $strength += (($military->tanks ?? 0) * $weights['tanks']);
        $strength += (($military->aircraft ?? 0) * $weights['aircraft']);
        $strength += (($military->ships ?? 0) * $weights['ships']);
        $strength += (($military->missiles ?? 0) * $weights['missiles']);
        $strength += (($military->nukes ?? 0) * $weights['nukes']);

        // Include a soft bonus for city scale to keep parity stable when intel is stale.
        $strength += max(0, ($nation->num_cities ?? 0) * 500);

        return max(1.0, $strength);
    }

    /**
     * Build a consistent factor structure for UI breakdowns.
     *
     * @param  array<string, float>  $weights
     */
    protected function factorEntry(
        string $key,
        float $value,
        array $weights,
        ?string $reason = null,
        ?float $impact = null,
        array $extra = []
    ): array {
        $weight = $weights[$key] ?? 0.0;
        $impactValue = $impact ?? ($weight * $value * 100);

        return array_merge([
            'value' => round($value, 4),
            'weight' => round($weight, 4),
            'impact' => round($impactValue, 2),
            'reason' => $reason,
        ], $extra);
    }

    protected function normalize(float|int $value, float $cap): float
    {
        if ($cap <= 0) {
            return 0;
        }

        return round(min(1, $value / $cap), 4);
    }

    /**
     * Determine whether the friendly nation can declare war on the enemy based on score range.
     */
    public function canAttack(Nation $source, Nation $target): bool
    {
        $min = $source->score * 0.75;
        $max = $source->score * 2.5;

        return $target->score >= $min && $target->score <= $max;
    }
}
