<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\City;
use App\Models\Nation;
use App\Nel\CityNelHelper;
use App\Nel\MathNelHelper;
use App\Nel\NationNelHelper;
use App\Nel\NelEngine;
use App\Services\AllianceMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AuditService
{
    private const CHUNK_SIZE = 200;

    private const RUN_LOCK_KEY = 'audits:run';

    private const RUN_LOCK_SECONDS = 5400;

    public function __construct(
        private readonly NelEngine $nelEngine,
        private readonly NationAuditMapper $nationAuditMapper,
        private readonly CityAuditMapper $cityAuditMapper,
        private readonly AllianceMembershipService $membershipService,
        private readonly NationNelHelper $nationNelHelper,
        private readonly CityNelHelper $cityNelHelper,
        private readonly MathNelHelper $mathNelHelper,
    ) {}

    public function runAllEnabledRules(): bool
    {
        return (bool) Cache::lock(self::RUN_LOCK_KEY, self::RUN_LOCK_SECONDS)
            ->get(function (): bool {
                $this->runAllEnabledRulesWithoutLock();

                return true;
            });
    }

    private function runAllEnabledRulesWithoutLock(): void
    {
        $rules = AuditRule::query()->enabled()->get();

        if ($rules->isEmpty()) {
            return;
        }

        $nationRules = $this->compileRules($rules->where('target_type', AuditTargetType::Nation));
        $cityRules = $this->compileRules($rules->where('target_type', AuditTargetType::City));
        $allianceIds = $this->membershipService->getAllianceIds()->all();
        $evaluatedAt = now();

        $this->clearIneligibleViolations($allianceIds);

        if ($nationRules->isNotEmpty()) {
            $nationHelpers = $this->helperBindingsFor(AuditTargetType::Nation);

            $this->activeMemberNationQuery($allianceIds)
                ->select([
                    'id',
                    'alliance_id',
                    'alliance_position',
                    'nation_name',
                    'leader_name',
                    'continent',
                    'war_policy',
                    'domestic_policy',
                    'color',
                    'num_cities',
                    'score',
                    'population',
                    'projects',
                    'project_bits',
                    'wars_won',
                    'wars_lost',
                    'offensive_wars_count',
                    'defensive_wars_count',
                    'gross_national_income',
                    'gross_domestic_product',
                    'commendations',
                    'denouncements',
                ])
                ->with([
                    'resources:nation_id,money,coal,oil,uranium,iron,bauxite,lead,gasoline,munitions,steel,aluminum,food,credits',
                    'military:nation_id,soldiers,tanks,aircraft,ships,missiles,nukes,spies',
                    'accountProfile:nation_id,credits,last_active,discord_id',
                    'latestSignIn' => function ($query): void {
                        $query->select('nation_sign_ins.id', 'nation_sign_ins.nation_id', 'nation_sign_ins.mmr_score');
                    },
                ])
                ->chunkById(self::CHUNK_SIZE, function (Collection $nations) use ($nationRules, $nationHelpers, $evaluatedAt): void {
                    $this->runRulesForNationChunk($nations, $nationRules, $nationHelpers, $evaluatedAt);
                });
        }

        if ($cityRules->isNotEmpty()) {
            $cityHelpers = $this->helperBindingsFor(AuditTargetType::City);

            City::query()
                ->select([
                    'id',
                    'nation_id',
                    'name',
                    'infrastructure',
                    'land',
                    'powered',
                    'oil_power',
                    'wind_power',
                    'coal_power',
                    'nuclear_power',
                    'coal_mine',
                    'oil_well',
                    'uranium_mine',
                    'farm',
                    'barracks',
                    'police_station',
                    'hospital',
                    'recycling_center',
                    'subway',
                    'supermarket',
                    'bank',
                    'shopping_mall',
                    'stadium',
                    'lead_mine',
                    'iron_mine',
                    'bauxite_mine',
                    'oil_refinery',
                    'aluminum_refinery',
                    'steel_mill',
                    'munitions_factory',
                    'factory',
                    'hangar',
                    'drydock',
                ])
                ->whereHas('nation', function (Builder $query) use ($allianceIds): void {
                    $this->applyMemberConstraints($query, $allianceIds);
                })
                ->with(['nation:id,nation_name,leader_name,score,num_cities,color'])
                ->chunkById(self::CHUNK_SIZE, function (Collection $cities) use ($cityRules, $cityHelpers, $evaluatedAt): void {
                    $this->runRulesForCityChunk($cities, $cityRules, $cityHelpers, $evaluatedAt);
                });
        }
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     * @return Collection<int, CompiledAuditRule>
     */
    private function compileRules(Collection $rules): Collection
    {
        return $rules
            ->map(function (AuditRule $rule): ?CompiledAuditRule {
                try {
                    return new CompiledAuditRule($rule, $this->nelEngine->parse($rule->expression));
                } catch (Throwable $exception) {
                    Log::error('Audit rule compilation failed', [
                        'rule_id' => $rule->id,
                        'target_type' => $rule->target_type->value,
                        'message' => $exception->getMessage(),
                    ]);

                    return null;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, Nation>  $nations
     * @param  Collection<int, CompiledAuditRule>  $rules
     * @param  array<string, callable>  $helpers
     */
    protected function runRulesForNationChunk(
        Collection $nations,
        Collection $rules,
        array $helpers,
        Carbon $evaluatedAt,
    ): void {
        $violations = [];
        $resolvedTargetKeysByRule = [];

        foreach ($nations as $nation) {
            $variables = $this->nationAuditMapper->buildVariables($nation);
            $targetKey = self::targetKeyFor(AuditTargetType::Nation, $nation->id, null);

            foreach ($rules as $compiledRule) {
                $isViolation = $this->evaluateRule(
                    $compiledRule,
                    $variables,
                    AuditTargetType::Nation,
                    $nation->id,
                    null,
                    $helpers,
                );

                if ($isViolation === null) {
                    continue;
                }

                if ($isViolation) {
                    $violations[] = $this->violationRow(
                        $compiledRule,
                        AuditTargetType::Nation,
                        $nation->id,
                        null,
                        $targetKey,
                        $evaluatedAt,
                    );

                    continue;
                }

                $resolvedTargetKeysByRule[$compiledRule->rule->id][] = $targetKey;
            }
        }

        $this->persistEvaluationChanges($violations, $resolvedTargetKeysByRule, AuditTargetType::Nation);
    }

    /**
     * @param  Collection<int, City>  $cities
     * @param  Collection<int, CompiledAuditRule>  $rules
     * @param  array<string, callable>  $helpers
     */
    protected function runRulesForCityChunk(
        Collection $cities,
        Collection $rules,
        array $helpers,
        Carbon $evaluatedAt,
    ): void {
        $violations = [];
        $resolvedTargetKeysByRule = [];

        foreach ($cities as $city) {
            $variables = $this->cityAuditMapper->buildVariables($city);
            $targetKey = self::targetKeyFor(AuditTargetType::City, $city->nation_id, $city->id);

            foreach ($rules as $compiledRule) {
                $isViolation = $this->evaluateRule(
                    $compiledRule,
                    $variables,
                    AuditTargetType::City,
                    $city->nation_id,
                    $city->id,
                    $helpers,
                );

                if ($isViolation === null) {
                    continue;
                }

                if ($isViolation) {
                    $violations[] = $this->violationRow(
                        $compiledRule,
                        AuditTargetType::City,
                        $city->nation_id,
                        $city->id,
                        $targetKey,
                        $evaluatedAt,
                    );

                    continue;
                }

                $resolvedTargetKeysByRule[$compiledRule->rule->id][] = $targetKey;
            }
        }

        $this->persistEvaluationChanges($violations, $resolvedTargetKeysByRule, AuditTargetType::City);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, callable>  $helpers
     */
    protected function evaluateRule(
        CompiledAuditRule $compiledRule,
        array $variables,
        AuditTargetType $targetType,
        ?int $nationId,
        ?int $cityId,
        array $helpers,
    ): ?bool {
        try {
            return (bool) $this->nelEngine->evaluateParsed(
                $compiledRule->expression,
                $variables,
                helpers: $helpers,
            );
        } catch (Throwable $exception) {
            Log::error('Audit rule evaluation failed', [
                'rule_id' => $compiledRule->rule->id,
                'target_type' => $targetType->value,
                'nation_id' => $nationId,
                'city_id' => $cityId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function violationRow(
        CompiledAuditRule $compiledRule,
        AuditTargetType $targetType,
        ?int $nationId,
        ?int $cityId,
        string $targetKey,
        Carbon $evaluatedAt,
    ): array {
        return [
            'audit_rule_id' => $compiledRule->rule->id,
            'target_type' => $targetType->value,
            'target_key' => $targetKey,
            'nation_id' => $nationId,
            'city_id' => $cityId,
            'first_detected_at' => $evaluatedAt,
            'last_evaluated_at' => $evaluatedAt,
            'created_at' => $evaluatedAt,
            'updated_at' => $evaluatedAt,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     * @param  array<int, array<int, string>>  $resolvedTargetKeysByRule
     */
    private function persistEvaluationChanges(
        array $violations,
        array $resolvedTargetKeysByRule,
        AuditTargetType $targetType,
    ): void {
        if ($violations !== []) {
            AuditResult::query()->upsert(
                $violations,
                ['audit_rule_id', 'target_type', 'target_key'],
                ['nation_id', 'city_id', 'last_evaluated_at', 'updated_at'],
            );
        }

        foreach ($resolvedTargetKeysByRule as $ruleId => $targetKeys) {
            foreach (array_chunk(array_values(array_unique($targetKeys)), 1000) as $targetKeyChunk) {
                AuditResult::query()
                    ->where('audit_rule_id', $ruleId)
                    ->where('target_type', $targetType->value)
                    ->whereIn('target_key', $targetKeyChunk)
                    ->delete();
            }
        }
    }

    private static function targetKeyFor(AuditTargetType $targetType, ?int $nationId, ?int $cityId): string
    {
        return match ($targetType) {
            AuditTargetType::Nation => $nationId !== null
                ? "nation:{$nationId}"
                : throw new InvalidArgumentException('Nation audit targets require a nation ID.'),
            AuditTargetType::City => $cityId !== null
                ? "city:{$cityId}"
                : throw new InvalidArgumentException('City audit targets require a city ID.'),
        };
    }

    /**
     * @return array<string, callable>
     */
    private function helperBindingsFor(AuditTargetType $targetType): array
    {
        $helpers = [
            ...$this->mathNelHelper->bindings(),
            ...$this->nationNelHelper->bindings(),
        ];

        if ($targetType === AuditTargetType::City) {
            $helpers = [
                ...$helpers,
                ...$this->cityNelHelper->bindings(),
            ];
        }

        return $helpers;
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    protected function activeMemberNationQuery(array $allianceIds): Builder
    {
        return $this->applyMemberConstraints(Nation::query(), $allianceIds);
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    protected function applyMemberConstraints(Builder $query, array $allianceIds): Builder
    {
        return $query
            ->whereIn('alliance_id', $allianceIds)
            ->where(function (Builder $query): void {
                $query->whereNull('alliance_position')
                    ->orWhere('alliance_position', '!=', 'APPLICANT');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('vacation_mode_turns')
                    ->orWhere('vacation_mode_turns', '<=', 0);
            });
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    protected function applyIneligibleMemberConstraints(Builder $query, array $allianceIds): Builder
    {
        return $query
            ->whereNull('alliance_id')
            ->orWhereNotIn('alliance_id', $allianceIds)
            ->orWhere(function (Builder $query) use ($allianceIds): void {
                $query->whereIn('alliance_id', $allianceIds)
                    ->where(function (Builder $query): void {
                        $query->where('alliance_position', 'APPLICANT')
                            ->orWhere(function (Builder $query): void {
                                $query->whereNotNull('vacation_mode_turns')
                                    ->where('vacation_mode_turns', '>', 0);
                            });
                    });
            });
    }

    /**
     * @param  array<int, int>  $allianceIds
     */
    protected function clearIneligibleViolations(array $allianceIds): void
    {
        if ($allianceIds === []) {
            AuditResult::query()->delete();

            return;
        }

        AuditResult::query()
            ->where(function (Builder $query) use ($allianceIds): void {
                $query->whereDoesntHave('nation')
                    ->orWhereHas('nation', function (Builder $query) use ($allianceIds): void {
                        $this->applyIneligibleMemberConstraints($query, $allianceIds);
                    });
            })
            ->delete();
    }

    /**
     * @return Collection<int, mixed>
     */
    public function getNationViolations(Nation $nation): Collection
    {
        return $this->getNationViolationsById($nation->id);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function getNationViolationsById(int $nationId): Collection
    {
        return AuditResult::query()
            ->with('rule')
            ->where('target_type', AuditTargetType::Nation)
            ->where('nation_id', $nationId)
            ->get();
    }

    /**
     * @return array{nation: Collection<int, AuditResult>, cities: Collection<int, AuditResult>}
     */
    public function getNationAndCityViolationsForNation(Nation $nation): array
    {
        $cityIds = $nation->cities()->pluck('id');

        $nationResults = AuditResult::query()
            ->with('rule')
            ->where('target_type', AuditTargetType::Nation)
            ->where('nation_id', $nation->id)
            ->get();

        $cityResults = AuditResult::query()
            ->with(['rule', 'city'])
            ->where('target_type', AuditTargetType::City)
            ->whereIn('city_id', $cityIds)
            ->get();

        return [
            'nation' => $nationResults,
            'cities' => $cityResults,
        ];
    }
}
