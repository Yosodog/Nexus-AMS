<?php

namespace App\Services;

use App\DataTransferObjects\Raids\RaidAttacker;
use App\DataTransferObjects\Raids\RaidFinderFilters;
use App\DataTransferObjects\Raids\RaidFinderResult;
use App\DataTransferObjects\Raids\RaidValuation;
use App\DataTransferObjects\Raids\RaidWarContext;
use App\Models\RaidAllianceProfile;
use App\Models\RaidFinderImpression;
use App\Models\RaidTargetClaim;
use App\Models\RaidTargetProfile;
use App\Models\War;
use App\Services\Economy\MarketValuationService;
use App\Services\Raids\RaidActivity;
use App\Services\Raids\RaidAttackerFactory;
use App\Services\Raids\RaidTargetClaimService;
use App\Services\Raids\RaidValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ranks raid targets synchronously from precomputed target profiles.
 *
 * One indexed candidate query feeds the closed-form valuation; the query count does
 * not grow with the number of candidates.
 */
class RaidFinderService
{
    private const ALLIANCE_PROFILE_COLUMNS = [
        'alliance_id', 'alliance_score', 'counter_rate',
        'bank_money', 'bank_coal', 'bank_oil', 'bank_uranium', 'bank_iron', 'bank_bauxite',
        'bank_lead', 'bank_gasoline', 'bank_munitions', 'bank_steel', 'bank_aluminum', 'bank_food',
    ];

    public function __construct(
        private AllianceMembershipService $membership,
        private RaidPolicyService $policy,
        private RaidAttackerFactory $attackers,
        private RaidValuationService $valuation,
        private MarketValuationService $prices,
        private RaidTargetClaimService $claims,
    ) {}

    public function find(int $attackerNationId, RaidFinderFilters $filters): RaidFinderResult
    {
        $attacker = $this->attackers->forNation($attackerNationId);
        abort_unless($this->membership->contains($attacker->allianceId), 403, 'Nation does not belong to our alliance.');

        $key = "raid-finder:{$attackerNationId}:{$this->policy->version()}:{$filters->cacheKey()}";
        $compute = fn (): array => $this->rank($attacker, $filters);

        if ($filters->fresh) {
            $ranked = $compute();
            Cache::put($key, $ranked, (int) config('raids.finder.cache_seconds'));
        } else {
            $ranked = Cache::remember($key, (int) config('raids.finder.cache_seconds'), $compute);
        }

        $rows = $this->applyClaims($ranked['rows'], $attackerNationId, $filters->hideClaimed);
        $this->recordImpressions($attackerNationId, $rows);

        return new RaidFinderResult($rows, $ranked['meta']);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function rank(RaidAttacker $attacker, RaidFinderFilters $filters): array
    {
        $now = CarbonImmutable::now();
        $minimumScore = $attacker->score * (float) config('milcom.game_rules.declaration_score_minimum_multiplier');
        $maximumScore = $attacker->score * (float) config('milcom.game_rules.declaration_score_maximum_multiplier');
        $candidates = $this->candidates($attacker, $filters, $minimumScore, $maximumScore);
        $candidateIds = $candidates->pluck('nation_id')->map(fn (mixed $id): int => (int) $id)->all();
        $wars = $candidateIds === [] ? collect() : War::query()
            ->active()
            ->where(fn ($query) => $query
                ->whereIn('def_id', $candidateIds)
                ->orWhere('att_id', $attacker->nationId)
                ->orWhere('def_id', $attacker->nationId))
            ->get(['id', 'att_id', 'def_id']);
        $fighting = $wars
            ->flatMap(fn (War $war): array => match ($attacker->nationId) {
                (int) $war->att_id => [(int) $war->def_id],
                (int) $war->def_id => [(int) $war->att_id],
                default => [],
            })
            ->unique()
            ->all();
        $prices = $this->prices->current();

        $ranked = $candidates
            ->reject(fn (RaidTargetProfile $target): bool => in_array((int) $target->nation_id, $fighting, true))
            ->map(function (RaidTargetProfile $target) use ($attacker, $wars, $prices, $now): array {
                $alliance = $this->allianceProfile($target);
                $knownAttackers = $wars
                    ->filter(fn (War $war): bool => (int) $war->def_id === (int) $target->nation_id && (int) $war->att_id !== $attacker->nationId)
                    ->count();
                $context = new RaidWarContext(
                    max($knownAttackers, (int) $target->defensive_wars),
                    (float) ($alliance?->counter_rate ?? config('raids.valuation.default_counter_rate')),
                );

                return [
                    'target' => $target,
                    'context' => $context,
                    'valuation' => $this->valuation->evaluate($attacker, $target, $alliance, $context, $prices, $now),
                ];
            })
            ->filter(fn (array $candidate): bool => $filters->minExpectedNet === null
                || $candidate['valuation']->expectedNet >= $filters->minExpectedNet)
            ->filter(fn (array $candidate): bool => ! $filters->beatableOnly
                || ($candidate['valuation']->winProbability >= 0.5 && $candidate['valuation']->victoryProbability >= 0.5))
            ->sortByDesc(fn (array $candidate): float => $candidate['valuation']->expectedNet)
            ->take($filters->limit)
            ->values();

        return [
            'rows' => $ranked
                ->map(fn (array $candidate, int $index): array => $this->row($index + 1, $candidate['target'], $candidate['valuation'], $candidate['context'], $now))
                ->all(),
            'meta' => [
                'generated_at' => $now->toIso8601String(),
                'model_version' => RaidValuationService::MODEL_VERSION,
                'prices_at' => $prices->calculatedAt?->toIso8601String(),
                'candidate_count' => $candidates->count(),
                'attacker' => [
                    'id' => $attacker->nationId,
                    'score' => $attacker->score,
                    'range_min' => round($minimumScore, 2),
                    'range_max' => round($maximumScore, 2),
                    'offensive_wars' => $attacker->offensiveWars,
                    'offensive_capacity' => $attacker->offensiveCapacity,
                    'planning_only' => $attacker->offensiveWars >= $attacker->offensiveCapacity,
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, RaidTargetProfile>
     */
    private function candidates(RaidAttacker $attacker, RaidFinderFilters $filters, float $minimumScore, float $maximumScore): Collection
    {
        $profiles = (new RaidTargetProfile)->getTable();
        $allianceProfiles = (new RaidAllianceProfile)->getTable();
        $protected = $this->policy->protectedAllianceIds();

        return RaidTargetProfile::query()
            ->leftJoin('alliances', 'alliances.id', '=', $profiles.'.alliance_id')
            ->leftJoin($allianceProfiles, $allianceProfiles.'.alliance_id', '=', $profiles.'.alliance_id')
            ->select([
                $profiles.'.*',
                'alliances.name as alliance_name',
                ...collect(self::ALLIANCE_PROFILE_COLUMNS)
                    ->map(fn (string $column): string => "{$allianceProfiles}.{$column} as alliance_profile_{$column}")
                    ->all(),
            ])
            ->whereBetween($profiles.'.score', [$minimumScore, $maximumScore])
            ->where($profiles.'.nation_id', '!=', $attacker->nationId)
            ->where($profiles.'.vacation_mode_turns', 0)
            ->where($profiles.'.defensive_wars', '<', 3)
            ->where(fn ($query) => $query
                ->where($profiles.'.beige_turns', 0)
                ->orWhere($profiles.'.beige_turns', '<=', $filters->beigeWithinTurns))
            ->where(fn ($query) => $query
                ->where($profiles.'.alliance_id', 0)
                ->orWhereNotIn($profiles.'.alliance_id', $protected))
            ->when($filters->allianceScope === RaidFinderFilters::SCOPE_UNALIGNED, fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where($profiles.'.alliance_id', 0)
                    ->orWhere($profiles.'.alliance_position', 'APPLICANT')))
            ->when($filters->allianceScope === RaidFinderFilters::SCOPE_ALIGNED, fn ($query) => $query
                ->where($profiles.'.alliance_id', '>', 0)
                ->where(fn ($inner) => $inner
                    ->whereNull($profiles.'.alliance_position')
                    ->orWhere($profiles.'.alliance_position', '!=', 'APPLICANT')))
            ->when($filters->minInactiveDays !== null, fn ($query) => $query
                ->where($profiles.'.last_active', '<=', now()->subDays((int) $filters->minInactiveDays)))
            ->whereNotNull($profiles.'.computed_at')
            ->orderByDesc($profiles.'.projected_value')
            ->limit((int) config('raids.finder.candidate_pool'))
            ->get();
    }

    /**
     * Alliance bank and counter data selected alongside the candidate profile.
     */
    private function allianceProfile(RaidTargetProfile $target): ?RaidAllianceProfile
    {
        if ($target->getAttribute('alliance_profile_alliance_id') === null) {
            return null;
        }

        return (new RaidAllianceProfile)->forceFill(collect(self::ALLIANCE_PROFILE_COLUMNS)
            ->mapWithKeys(fn (string $column): array => [$column => $target->getAttribute('alliance_profile_'.$column)])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $rank, RaidTargetProfile $target, RaidValuation $valuation, RaidWarContext $context, CarbonImmutable $now): array
    {
        $allianceId = (int) $target->alliance_id;

        return [
            'rank' => $rank,
            'nation' => [
                'id' => (int) $target->nation_id,
                'nation_name' => (string) $target->nation_name,
                'leader_name' => (string) $target->leader_name,
                'alliance' => $allianceId > 0
                    ? ['id' => $allianceId, 'name' => (string) ($target->getAttribute('alliance_name') ?? "Alliance {$allianceId}")]
                    : null,
                'alliance_position' => $target->alliance_position,
                'num_cities' => (int) $target->num_cities,
                'score' => (float) $target->score,
                'last_active' => $target->last_active?->toIso8601String(),
                'activity_bucket' => RaidActivity::bucket($target->last_active, $now),
                'beige_turns' => (int) $target->beige_turns,
                'defensive_wars' => (int) $target->defensive_wars,
                'soldiers' => (int) $target->soldiers,
                'tanks' => (int) $target->tanks,
                'aircraft' => (int) $target->aircraft,
                'ships' => (int) $target->ships,
                'war_policy' => $target->war_policy,
            ],
            'valuation' => [
                'expected_net' => $valuation->expectedNet,
                'expected_net_low' => $valuation->expectedNetLow,
                'expected_net_high' => $valuation->expectedNetHigh,
                'gross_loot' => $valuation->grossLoot,
                'win_probability' => $valuation->winProbability,
                'victory_probability' => $valuation->victoryProbability,
                'beige_share' => $valuation->beigeShare,
                'expected_attacks' => $valuation->expectedAttacks,
                'duration_hours' => $valuation->durationHours,
                'confidence' => $valuation->confidence,
                'components' => $valuation->components,
                'loot_resources' => $valuation->lootResources,
                'cost_resources' => $valuation->costResources,
                'stockpile' => $valuation->stockpile,
                'competition' => ['other_attackers' => $context->otherAttackers],
                'counter' => ['probability' => $valuation->counterProbability],
                'assumptions' => $valuation->assumptions,
            ],
            'claim' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyClaims(array $rows, int $attackerNationId, bool $hideClaimed): array
    {
        $claims = $this->claims->activeForTargets(array_map(fn (array $row): int => $row['nation']['id'], $rows));

        return collect($rows)
            ->map(function (array $row) use ($claims, $attackerNationId): array {
                /** @var RaidTargetClaim|null $claim */
                $claim = $claims->get($row['nation']['id']);
                $row['claim'] = $claim === null ? null : [
                    'id' => (int) $claim->id,
                    'nation_id' => (int) $claim->nation_id,
                    'leader_name' => $claim->getAttribute('claimer_leader_name'),
                    'expires_at' => $claim->expires_at?->toIso8601String(),
                    'mine' => (int) $claim->nation_id === $attackerNationId,
                ];

                return $row;
            })
            ->reject(fn (array $row): bool => $hideClaimed && $row['claim'] !== null && ! $row['claim']['mine'])
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordImpressions(int $attackerNationId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        RaidFinderImpression::query()->upsert(
            array_map(fn (array $row): array => [
                'attacker_nation_id' => $attackerNationId,
                'target_nation_id' => $row['nation']['id'],
                'rank' => $row['rank'],
                'expected_net' => $row['valuation']['expected_net'],
                'shown_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['attacker_nation_id', 'target_nation_id'],
            ['rank', 'expected_net', 'shown_at', 'updated_at'],
        );
    }
}
