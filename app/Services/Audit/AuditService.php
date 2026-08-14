<?php

namespace App\Services\Audit;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditTargetType;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\City;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class AuditService
{
    private const CHUNK_SIZE = 200;

    private const RUN_LOCK_KEY = 'audits:run';

    private const RUN_LOCK_SECONDS = 5400;

    public function __construct(
        private readonly AuditRuleEvaluator $evaluator,
        private readonly AuditRuleDefinitionService $definitions,
        private readonly AuditContextLoader $contextLoader,
        private readonly NationAuditMapper $nationAuditMapper,
        private readonly CityAuditMapper $cityAuditMapper,
        private readonly AllianceMembershipService $membershipService,
        private readonly AuditRemediationService $remediationService,
    ) {}

    public function runAllEnabledRules(): bool
    {
        return (bool) Cache::lock(self::RUN_LOCK_KEY, self::RUN_LOCK_SECONDS)
            ->get(function (): bool {
                $this->runRules(AuditRule::query()->enabled()->orderBy('id')->get());

                return true;
            });
    }

    public function runRule(AuditRule $rule): bool
    {
        if (! $rule->enabled) {
            return true;
        }

        return (bool) Cache::lock(self::RUN_LOCK_KEY, self::RUN_LOCK_SECONDS)
            ->get(function () use ($rule): bool {
                return (bool) Cache::lock("audits:rule:{$rule->id}", self::RUN_LOCK_SECONDS)
                    ->get(function () use ($rule): bool {
                        $freshRule = AuditRule::query()->find($rule->id);

                        if ($freshRule?->enabled) {
                            $this->runRules(collect([$freshRule]));
                        }

                        return true;
                    });
            });
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     */
    private function runRules(Collection $rules): void
    {
        if ($rules->isEmpty()) {
            return;
        }

        $evaluatedAt = now();
        $validRules = $this->prepareRules($rules, $evaluatedAt);

        if ($validRules->isEmpty()) {
            return;
        }

        $this->clearIneligibleFindings($this->membershipService->getAllianceIds()->all());
        $runState = $validRules->mapWithKeys(static fn (AuditRule $rule): array => [
            $rule->id => [
                'warnings' => [],
                'errors' => 0,
                'duration_ms' => 0,
            ],
        ])->all();

        foreach (AuditTargetType::cases() as $targetType) {
            $targetRules = $validRules
                ->filter(fn (AuditRule $rule): bool => $rule->target_type === $targetType)
                ->values();

            if ($targetRules->isNotEmpty()) {
                $this->runTargetRules($targetType, $targetRules, $evaluatedAt, $runState);
            }
        }

        $this->finishRuleHealth($validRules, $runState, $evaluatedAt);
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     * @return Collection<int, AuditRule>
     */
    private function prepareRules(Collection $rules, Carbon $evaluatedAt): Collection
    {
        return $rules->filter(function (AuditRule $rule) use ($evaluatedAt): bool {
            $inspection = $this->definitions->inspect($rule->definition, $rule->target_type);

            if ($inspection['errors'] !== [] || $inspection['normalized'] === null) {
                $this->saveEvaluationState($rule, [
                    'last_evaluation_status' => AuditEvaluationStatus::Failed,
                    'last_evaluated_at' => $evaluatedAt,
                    'last_evaluation_error' => 'This rule has an invalid definition and was not evaluated.',
                    'last_evaluation_duration_ms' => 0,
                ]);

                Log::error('Audit rule definition is invalid', [
                    'rule_id' => $rule->id,
                    'target_type' => $rule->target_type->value,
                    'validation_errors' => $inspection['errors'],
                ]);

                return false;
            }

            if (! $this->definitions->hasCriteria($inspection['normalized'])) {
                $this->saveEvaluationState($rule, [
                    'last_evaluation_status' => AuditEvaluationStatus::Failed,
                    'last_evaluated_at' => $evaluatedAt,
                    'last_evaluation_error' => 'Enabled rules must contain at least one alert condition.',
                    'last_evaluation_duration_ms' => 0,
                ]);

                return false;
            }

            $rule->definition = $inspection['normalized'];
            $this->saveEvaluationState($rule, [
                'last_evaluation_status' => AuditEvaluationStatus::Pending,
                'last_evaluation_error' => null,
            ]);

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     * @param  array<int, array{warnings: array<int, string>, errors: int, duration_ms: int}>  &$runState
     */
    private function runTargetRules(
        AuditTargetType $targetType,
        Collection $rules,
        Carbon $evaluatedAt,
        array &$runState,
    ): void {
        $ruleDefinitions = $rules->mapWithKeys(
            static fn (AuditRule $rule): array => [$rule->id => $rule->definition],
        )->all();
        $summaries = $rules->mapWithKeys(fn (AuditRule $rule): array => [
            $rule->id => $this->definitions->summarize($rule->definition, $rule->target_type),
        ])->all();

        $this->contextLoader
            ->query($targetType, array_values($ruleDefinitions))
            ->chunkById(self::CHUNK_SIZE, function (Collection $targets) use (
                $targetType,
                $rules,
                $evaluatedAt,
                $summaries,
                &$runState,
            ): void {
                $matches = [];
                $resolvedTargetKeysByRule = [];

                foreach ($targets as $target) {
                    try {
                        $context = $this->contextFor($targetType, $target);
                        [$nationId, $cityId, $targetKey] = $this->targetIdentity($targetType, $target);
                    } catch (Throwable $exception) {
                        foreach ($rules as $rule) {
                            $runState[$rule->id]['errors']++;
                        }

                        Log::error('Audit target context could not be loaded', [
                            'target_type' => $targetType->value,
                            'target_id' => $target->getKey(),
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    foreach ($rules as $rule) {
                        try {
                            $evaluation = $this->evaluator->evaluate(
                                $targetType,
                                $rule->definition,
                                $context,
                                $evaluatedAt,
                            );
                            $runState[$rule->id]['duration_ms'] += $evaluation->durationMs;
                            $runState[$rule->id]['warnings'] = array_values(array_unique([
                                ...$runState[$rule->id]['warnings'],
                                ...$evaluation->warnings,
                            ]));

                            if ($evaluation->matched) {
                                $matches[] = $this->findingRow(
                                    $rule,
                                    $targetType,
                                    $nationId,
                                    $cityId,
                                    $targetKey,
                                    $evaluation,
                                    $summaries[$rule->id],
                                    $evaluatedAt,
                                );
                            } else {
                                $resolvedTargetKeysByRule[$rule->id][] = $targetKey;
                            }
                        } catch (Throwable $exception) {
                            $runState[$rule->id]['errors']++;

                            Log::error('Audit target evaluation failed', [
                                'rule_id' => $rule->id,
                                'target_type' => $targetType->value,
                                'nation_id' => $nationId,
                                'city_id' => $cityId,
                                'exception' => $exception,
                            ]);
                        }
                    }
                }

                $this->persistEvaluationChanges($matches, $resolvedTargetKeysByRule, $targetType);
            });
    }

    /**
     * @param  Collection<int, AuditRule>  $rules
     * @param  array<int, array{warnings: array<int, string>, errors: int, duration_ms: int}>  $runState
     */
    private function finishRuleHealth(Collection $rules, array $runState, Carbon $evaluatedAt): void
    {
        $matchCounts = AuditResult::query()
            ->current()
            ->whereIn('audit_rule_id', $rules->pluck('id')->all())
            ->selectRaw('audit_rule_id, COUNT(*) AS aggregate')
            ->groupBy('audit_rule_id')
            ->pluck('aggregate', 'audit_rule_id');

        foreach ($rules as $rule) {
            $state = $runState[$rule->id];
            $status = match (true) {
                $state['errors'] > 0 => AuditEvaluationStatus::Failed,
                $state['warnings'] !== [] => AuditEvaluationStatus::Warning,
                default => AuditEvaluationStatus::Success,
            };
            $message = match ($status) {
                AuditEvaluationStatus::Failed => $state['errors'].' target(s) could not be evaluated; their existing findings were preserved.',
                AuditEvaluationStatus::Warning => count($state['warnings']).' missing or invalid data warning(s) occurred during evaluation.',
                default => null,
            };

            $this->saveEvaluationState($rule, [
                'last_evaluation_status' => $status,
                'last_evaluated_at' => $evaluatedAt,
                'last_match_count' => (int) ($matchCounts[$rule->id] ?? 0),
                'last_evaluation_error' => $message,
                'last_evaluation_duration_ms' => $state['duration_ms'],
            ]);
        }
    }

    /**
     * Evaluation health is operational state and must not make a rule look admin-edited.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveEvaluationState(AuditRule $rule, array $attributes): void
    {
        $timestamps = $rule->timestamps;
        $rule->timestamps = false;

        try {
            $rule->forceFill($attributes)->save();
        } finally {
            $rule->timestamps = $timestamps;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(AuditTargetType $targetType, Model $target): array
    {
        return match ($targetType) {
            AuditTargetType::Nation => $this->nationAuditMapper->buildContext($this->asNation($target)),
            AuditTargetType::City => $this->cityAuditMapper->buildContext($this->asCity($target)),
        };
    }

    /**
     * @return array{int, int|null, string}
     */
    private function targetIdentity(AuditTargetType $targetType, Model $target): array
    {
        return match ($targetType) {
            AuditTargetType::Nation => [
                (int) $target->getKey(),
                null,
                self::targetKeyFor($targetType, (int) $target->getKey(), null),
            ],
            AuditTargetType::City => [
                (int) $this->asCity($target)->nation_id,
                (int) $target->getKey(),
                self::targetKeyFor($targetType, (int) $this->asCity($target)->nation_id, (int) $target->getKey()),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function findingRow(
        AuditRule $rule,
        AuditTargetType $targetType,
        int $nationId,
        ?int $cityId,
        string $targetKey,
        AuditEvaluationResult $evaluation,
        string $summary,
        Carbon $evaluatedAt,
    ): array {
        return [
            'audit_rule_id' => $rule->id,
            'rule_revision' => $rule->revision,
            'target_type' => $targetType->value,
            'target_key' => $targetKey,
            'nation_id' => $nationId,
            'city_id' => $cityId,
            'details' => json_encode([
                'rule_revision' => $rule->revision,
                'summary' => $summary,
                'evidence' => $evaluation->evidence,
                'warnings' => $evaluation->warnings,
                'evaluated_at' => $evaluatedAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'first_detected_at' => $evaluatedAt,
            'last_evaluated_at' => $evaluatedAt,
            'created_at' => $evaluatedAt,
            'updated_at' => $evaluatedAt,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, array<int, string>>  $resolvedTargetKeysByRule
     */
    private function persistEvaluationChanges(
        array $matches,
        array $resolvedTargetKeysByRule,
        AuditTargetType $targetType,
    ): void {
        DB::transaction(function () use ($matches, $resolvedTargetKeysByRule, $targetType): void {
            $existingKeys = collect();

            if ($matches !== []) {
                $ruleIds = collect($matches)->pluck('audit_rule_id')->unique()->values()->all();
                $targetKeys = collect($matches)->pluck('target_key')->unique()->values()->all();

                $existingKeys = AuditResult::query()
                    ->where('target_type', $targetType->value)
                    ->whereIn('audit_rule_id', $ruleIds)
                    ->whereIn('target_key', $targetKeys)
                    ->get(['id', 'audit_rule_id', 'target_key'])
                    ->mapWithKeys(static fn (AuditResult $result): array => [
                        $result->audit_rule_id.'|'.$result->target_key => true,
                    ]);

                AuditResult::query()->upsert(
                    $matches,
                    ['audit_rule_id', 'target_type', 'target_key'],
                    [
                        'rule_revision',
                        'nation_id',
                        'city_id',
                        'details',
                        'last_evaluated_at',
                        'updated_at',
                    ],
                );

                AuditResult::query()
                    ->with('rule')
                    ->where('target_type', $targetType->value)
                    ->whereIn('audit_rule_id', $ruleIds)
                    ->whereIn('target_key', $targetKeys)
                    ->get()
                    ->each(function (AuditResult $result) use ($existingKeys): void {
                        if (! $existingKeys->has($result->audit_rule_id.'|'.$result->target_key)) {
                            $this->remediationService->recordEvent($result, 'opened');
                        }
                    });
            }

            foreach ($resolvedTargetKeysByRule as $ruleId => $targetKeys) {
                foreach (array_chunk(array_values(array_unique($targetKeys)), 1000) as $targetKeyChunk) {
                    $resolved = AuditResult::query()
                        ->with('rule')
                        ->where('audit_rule_id', $ruleId)
                        ->where('target_type', $targetType->value)
                        ->whereIn('target_key', $targetKeyChunk)
                        ->get();

                    $resolved->each(fn (AuditResult $result) => $this->remediationService->recordEvent($result, 'resolved'));

                    if ($resolved->isNotEmpty()) {
                        AuditResult::query()->whereKey($resolved->modelKeys())->delete();
                    }
                }
            }
        });
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
     * @param  array<int, int>  $allianceIds
     */
    private function clearIneligibleFindings(array $allianceIds): void
    {
        $query = AuditResult::query();

        if ($allianceIds !== []) {
            $query->where(function (Builder $query) use ($allianceIds): void {
                $query->whereDoesntHave('nation')
                    ->orWhereHas('nation', function (Builder $query) use ($allianceIds): void {
                        $query
                            ->where(function (Builder $query) use ($allianceIds): void {
                                $query->whereNull('alliance_id')->orWhereNotIn('alliance_id', $allianceIds);
                            })
                            ->orWhere('alliance_position', 'APPLICANT')
                            ->orWhere('vacation_mode_turns', '>', 0);
                    });
            });
        }

        $query->with('rule')->chunkById(500, function (Collection $results): void {
            DB::transaction(function () use ($results): void {
                $results->each(fn (AuditResult $result) => $this->remediationService->recordEvent($result, 'removed_ineligible'));
                AuditResult::query()->whereKey($results->modelKeys())->delete();
            });
        });
    }

    public function getNationViolations(Nation $nation): Collection
    {
        return $this->getNationViolationsById($nation->id);
    }

    public function getNationViolationsById(int $nationId): Collection
    {
        return AuditResult::query()
            ->current()
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
        $nationResults = AuditResult::query()
            ->current()
            ->with('rule')
            ->where('target_type', AuditTargetType::Nation)
            ->where('nation_id', $nation->id)
            ->get();
        $cityResults = AuditResult::query()
            ->current()
            ->with(['rule', 'city'])
            ->where('target_type', AuditTargetType::City)
            ->where('nation_id', $nation->id)
            ->get();

        return [
            'nation' => $nationResults,
            'cities' => $cityResults,
        ];
    }

    private function asNation(Model $model): Nation
    {
        if (! $model instanceof Nation) {
            throw new InvalidArgumentException('Expected a nation audit target.');
        }

        return $model;
    }

    private function asCity(Model $model): City
    {
        if (! $model instanceof City) {
            throw new InvalidArgumentException('Expected a city audit target.');
        }

        return $model;
    }
}
