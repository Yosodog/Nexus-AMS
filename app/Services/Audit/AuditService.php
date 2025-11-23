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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditService
{
    public function __construct(
        private readonly NelEngine $nelEngine,
        private readonly NationAuditMapper $nationAuditMapper,
        private readonly CityAuditMapper $cityAuditMapper,
        private readonly AllianceMembershipService $membershipService,
        private readonly NationNelHelper $nationNelHelper,
        private readonly CityNelHelper $cityNelHelper,
        private readonly MathNelHelper $mathNelHelper,
    ) {}

    public function runAllEnabledRules(): void
    {
        $rules = AuditRule::query()->enabled()->get();

        if ($rules->isEmpty()) {
            return;
        }

        $nationRules = $rules->where('target_type', AuditTargetType::Nation);
        $cityRules = $rules->where('target_type', AuditTargetType::City);
        $allianceIds = $this->membershipService->getAllianceIds()->all();

        if ($nationRules->isNotEmpty()) {
            Nation::query()
                ->whereIn('alliance_id', $allianceIds)
                ->with(['resources', 'military', 'accountProfile', 'latestSignIn'])
                ->chunkById(200, function (Collection $nations) use ($nationRules): void {
                    $nations->each(function (Nation $nation) use ($nationRules): void {
                        $this->runRulesForNation($nation, $nationRules);
                    });
                });
        }

        if ($cityRules->isNotEmpty()) {
            City::query()
                ->whereHas('nation', function ($query) use ($allianceIds): void {
                    $query->whereIn('alliance_id', $allianceIds);
                })
                ->with(['nation'])
                ->chunkById(200, function (Collection $cities) use ($cityRules): void {
                    $cities->each(function (City $city) use ($cityRules): void {
                        $this->runRulesForCity($city, $cityRules);
                    });
                });
        }
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     */
    protected function runRulesForNation(Nation $nation, Collection $rules): void
    {
        $variables = $this->nationAuditMapper->buildVariables($nation);

        foreach ($rules as $rule) {
            $this->evaluateRule($rule, $variables, AuditTargetType::Nation, $nation->id, null);
        }
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     */
    protected function runRulesForCity(City $city, Collection $rules): void
    {
        $variables = $this->cityAuditMapper->buildVariables($city);

        foreach ($rules as $rule) {
            $this->evaluateRule($rule, $variables, AuditTargetType::City, $city->nation_id, $city->id);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function evaluateRule(
        AuditRule $rule,
        array $variables,
        AuditTargetType $targetType,
        ?int $nationId,
        ?int $cityId,
    ): void {
        try {
            $result = $this->nelEngine->evaluate(
                $rule->expression,
                $variables,
                helpers: $this->helperBindingsFor($targetType)
            );
        } catch (Throwable $exception) {
            Log::error('Audit rule evaluation failed', [
                'rule_id' => $rule->id,
                'target_type' => $targetType->value,
                'nation_id' => $nationId,
                'city_id' => $cityId,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $isViolation = (bool) $result;

        if ($isViolation) {
            $this->upsertViolation($rule, $targetType, $nationId, $cityId);
        } else {
            $this->clearResult($rule, $targetType, $nationId, $cityId);
        }
    }

    protected function upsertViolation(
        AuditRule $rule,
        AuditTargetType $targetType,
        ?int $nationId,
        ?int $cityId,
    ): void {
        $attributes = [
            'audit_rule_id' => $rule->id,
            'target_type' => $targetType,
            'nation_id' => $nationId,
            'city_id' => $cityId,
        ];

        $existing = AuditResult::query()->where($attributes)->first();

        if ($existing) {
            $existing->forceFill([
                'last_evaluated_at' => now(),
            ])->save();

            return;
        }

        AuditResult::query()->create([
            ...$attributes,
            'first_detected_at' => now(),
            'last_evaluated_at' => now(),
        ]);
    }

    protected function clearResult(
        AuditRule $rule,
        AuditTargetType $targetType,
        ?int $nationId,
        ?int $cityId,
    ): void {
        AuditResult::query()->where([
            'audit_rule_id' => $rule->id,
            'target_type' => $targetType,
            'nation_id' => $nationId,
            'city_id' => $cityId,
        ])->delete();
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

    public function getNationViolations(Nation $nation): Collection
    {
        return $this->getNationViolationsById($nation->id);
    }

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
