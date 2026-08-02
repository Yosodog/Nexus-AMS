<?php

namespace App\Services\Audit;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditTargetType;
use App\Jobs\RunAuditRuleJob;
use App\Models\AuditResult;
use App\Models\AuditRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AuditRuleLifecycleService
{
    public function __construct(
        private readonly AuditRuleDefinitionService $definitions,
        private readonly AuditRemediationService $remediationService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): AuditRule
    {
        return DB::transaction(function () use ($attributes, $actor): AuditRule {
            $enabled = (bool) ($attributes['enabled'] ?? false);
            $rule = AuditRule::query()->create([
                ...$attributes,
                'revision' => 1,
                'last_evaluation_status' => $enabled
                    ? AuditEvaluationStatus::Pending
                    : AuditEvaluationStatus::NeverRun,
                'last_match_count' => 0,
                'last_evaluation_error' => null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($enabled) {
                DB::afterCommit(static fn () => RunAuditRuleJob::dispatch($rule->id));
            }

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AuditRule $rule, array $attributes, User $actor): AuditRule
    {
        return DB::transaction(function () use ($rule, $attributes, $actor): AuditRule {
            $locked = AuditRule::query()->lockForUpdate()->findOrFail($rule->id);
            $oldDefinition = is_array($locked->definition) ? $locked->definition : null;
            $newDefinition = $attributes['definition'];
            $oldFingerprint = $oldDefinition === null
                ? null
                : $this->definitions->fingerprint($locked->target_type, $oldDefinition);
            $newTarget = $attributes['target_type'] instanceof AuditTargetType
                ? $attributes['target_type']
                : AuditTargetType::from((string) $attributes['target_type']);
            $newFingerprint = $this->definitions->fingerprint($newTarget, $newDefinition);
            $behaviorChanged = $oldFingerprint !== $newFingerprint || $locked->target_type !== $newTarget;
            $wasEnabled = (bool) $locked->enabled;
            $willBeEnabled = (bool) ($attributes['enabled'] ?? false);
            $reEnabled = ! $wasEnabled && $willBeEnabled;
            $disabled = $wasEnabled && ! $willBeEnabled;
            $revisionChanged = $behaviorChanged || $reEnabled;
            $nextRevision = (int) $locked->revision + ($revisionChanged ? 1 : 0);

            if ($behaviorChanged) {
                $this->closeFindings($locked, 'rule_revised', $actor, [
                    'previous_revision' => (int) $locked->revision,
                    'new_revision' => $nextRevision,
                ], delete: false);
            }

            if ($disabled) {
                $this->closeFindings($locked, 'rule_disabled', $actor, [
                    'revision' => (int) $locked->revision,
                ], delete: false);
            }

            if ($behaviorChanged || $disabled || $reEnabled) {
                AuditResult::query()->where('audit_rule_id', $locked->id)->delete();
            }

            $locked->fill([
                ...$attributes,
                'revision' => $nextRevision,
                'updated_by' => $actor->id,
            ]);

            if ($willBeEnabled && ($revisionChanged || ! $wasEnabled)) {
                $locked->forceFill([
                    'last_evaluation_status' => AuditEvaluationStatus::Pending,
                    'last_evaluated_at' => null,
                    'last_match_count' => 0,
                    'last_evaluation_error' => null,
                    'last_evaluation_duration_ms' => null,
                ]);
            } elseif (! $willBeEnabled && ($behaviorChanged || $disabled)) {
                $locked->forceFill([
                    'last_evaluation_status' => AuditEvaluationStatus::NeverRun,
                    'last_match_count' => 0,
                    'last_evaluation_error' => null,
                ]);
            }

            $locked->save();

            if ($willBeEnabled && $revisionChanged) {
                DB::afterCommit(static fn () => RunAuditRuleJob::dispatch($locked->id));
            }

            return $locked;
        });
    }

    public function disable(AuditRule $rule, User $actor): AuditRule
    {
        return DB::transaction(function () use ($rule, $actor): AuditRule {
            $locked = AuditRule::query()->lockForUpdate()->findOrFail($rule->id);

            if ($locked->enabled) {
                $this->closeFindings($locked, 'rule_disabled', $actor, [
                    'revision' => (int) $locked->revision,
                ]);
            }

            $locked->forceFill([
                'enabled' => false,
                'updated_by' => $actor->id,
                'last_evaluation_status' => AuditEvaluationStatus::NeverRun,
                'last_match_count' => 0,
                'last_evaluation_error' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function closeFindings(
        AuditRule $rule,
        string $eventType,
        User $actor,
        array $metadata,
        bool $delete = true,
    ): void {
        $findings = $rule->results()->with('rule')->get();

        $findings->each(fn (AuditResult $result) => $this->remediationService->recordEvent(
            $result,
            $eventType,
            $actor,
            $metadata,
        ));

        if ($delete && $findings->isNotEmpty()) {
            AuditResult::query()->whereKey($findings->modelKeys())->delete();
        }
    }
}
