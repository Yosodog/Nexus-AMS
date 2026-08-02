<?php

namespace App\Services\Audit;

use App\Enums\AuditTargetType;
use App\Models\City;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class AuditPreviewService
{
    private const SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly AuditRuleDefinitionService $definitions,
        private readonly AuditContextLoader $contextLoader,
        private readonly AuditRuleEvaluator $evaluator,
        private readonly NationAuditMapper $nationAuditMapper,
        private readonly CityAuditMapper $cityAuditMapper,
        private readonly AuditImpactConfirmationService $confirmations,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function preview(User $user, AuditTargetType $targetType, array $definition): array
    {
        $normalized = $this->definitions->normalize($definition, $targetType);
        $startedAt = hrtime(true);
        $matchCount = 0;
        $samples = [];
        $warnings = [];
        $failedTargets = 0;

        $this->contextLoader
            ->query($targetType, [$normalized])
            ->chunkById(200, function (Collection $targets) use (
                $targetType,
                $normalized,
                &$matchCount,
                &$samples,
                &$warnings,
                &$failedTargets,
            ): void {
                foreach ($targets as $target) {
                    try {
                        $evaluation = $this->evaluator->evaluate(
                            $targetType,
                            $normalized,
                            $this->contextFor($targetType, $target),
                        );
                    } catch (Throwable $exception) {
                        $failedTargets++;

                        Log::error('Audit preview target evaluation failed', [
                            'target_type' => $targetType->value,
                            'target_id' => $target->getKey(),
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    $warnings = array_values(array_unique([...$warnings, ...$evaluation->warnings]));

                    if (! $evaluation->matched) {
                        continue;
                    }

                    $matchCount++;

                    if (count($samples) < self::SAMPLE_LIMIT) {
                        $samples[] = [
                            ...$this->sampleIdentity($targetType, $target),
                            'evidence' => $evaluation->evidence,
                            'warnings' => $evaluation->warnings,
                        ];
                    }
                }
            });

        if ($failedTargets > 0) {
            throw new RuntimeException(
                "Impact evaluation failed for {$failedTargets} target(s). The rule cannot be activated until preview succeeds.",
            );
        }

        $fingerprint = $this->definitions->fingerprint($targetType, $normalized);

        return [
            'definition' => $normalized,
            'plain_language_summary' => $this->definitions->summarize($normalized, $targetType),
            'match_count' => $matchCount,
            'samples' => $samples,
            'warnings' => $warnings,
            'evaluation_time_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'definition_fingerprint' => $fingerprint,
            'confirmation_token' => $this->confirmations->issue($user, $targetType, $normalized),
            'sample_limit' => self::SAMPLE_LIMIT,
        ];
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
     * @return array{id: int, label: string, secondary_label: string|null, target_key: string}
     */
    private function sampleIdentity(AuditTargetType $targetType, Model $target): array
    {
        if ($targetType === AuditTargetType::Nation) {
            $nation = $this->asNation($target);

            return [
                'id' => (int) $nation->id,
                'label' => (string) ($nation->nation_name ?: "Nation {$nation->id}"),
                'secondary_label' => $nation->leader_name,
                'target_key' => "nation:{$nation->id}",
            ];
        }

        $city = $this->asCity($target);

        return [
            'id' => (int) $city->id,
            'label' => (string) ($city->name ?: "City {$city->id}"),
            'secondary_label' => $city->nation?->nation_name,
            'target_key' => "city:{$city->id}",
        ];
    }

    private function asNation(Model $model): Nation
    {
        if (! $model instanceof Nation) {
            throw new RuntimeException('Expected a nation preview target.');
        }

        return $model;
    }

    private function asCity(Model $model): City
    {
        if (! $model instanceof City) {
            throw new RuntimeException('Expected a city preview target.');
        }

        return $model;
    }
}
