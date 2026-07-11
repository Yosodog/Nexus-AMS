<?php

namespace App\Services\Audit;

use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\User;
use App\Services\AllianceMemberEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditRemediationService
{
    public function __construct(private readonly AllianceMemberEligibilityService $eligibilityService) {}

    public function acknowledge(User $actor, AuditResult $result, ?string $note = null): AuditResult
    {
        $this->assertOwnsResult($actor, $result);

        return DB::transaction(function () use ($actor, $result, $note): AuditResult {
            $locked = AuditResult::query()->lockForUpdate()->findOrFail($result->id);
            $this->assertOwnsResult($actor, $locked);

            $locked->forceFill([
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $actor->id,
                'remediation_note' => $this->normalizeNote($note) ?? $locked->remediation_note,
            ])->save();

            $this->recordEvent($locked, 'acknowledged', $actor, [
                'note_present' => $note !== null && trim($note) !== '',
            ]);

            return $locked->fresh(['rule', 'city']);
        });
    }

    public function snooze(User $actor, AuditResult $result, int $hours): AuditResult
    {
        $this->assertOwnsResult($actor, $result);
        $hours = min(max($hours, 1), 168);

        return DB::transaction(function () use ($actor, $result, $hours): AuditResult {
            $locked = AuditResult::query()->lockForUpdate()->findOrFail($result->id);
            $this->assertOwnsResult($actor, $locked);
            $snoozedUntil = now()->addHours($hours);

            $locked->forceFill([
                'snoozed_until' => $snoozedUntil,
                'snoozed_by_user_id' => $actor->id,
            ])->save();

            $this->recordEvent($locked, 'snoozed', $actor, [
                'snoozed_until' => $snoozedUntil->toIso8601String(),
            ]);

            return $locked->fresh(['rule', 'city']);
        });
    }

    public function updateByAdmin(
        User $actor,
        AuditResult $result,
        ?Carbon $dueAt,
        ?Carbon $waivedUntil,
        ?string $note,
        bool $clearWaiver = false,
    ): AuditResult {
        return DB::transaction(function () use ($actor, $result, $dueAt, $waivedUntil, $note, $clearWaiver): AuditResult {
            $locked = AuditResult::query()->lockForUpdate()->findOrFail($result->id);
            $effectiveWaiver = $clearWaiver ? null : $waivedUntil;

            $locked->forceFill([
                'due_at' => $dueAt,
                'waived_until' => $effectiveWaiver,
                'waived_by_user_id' => $effectiveWaiver ? $actor->id : null,
                'remediation_note' => $this->normalizeNote($note),
            ])->save();

            $this->recordEvent($locked, $effectiveWaiver ? 'waived' : 'admin_updated', $actor, [
                'due_at' => $dueAt?->toIso8601String(),
                'waived_until' => $effectiveWaiver?->toIso8601String(),
                'waiver_cleared' => $clearWaiver,
            ]);

            return $locked->fresh(['rule', 'nation', 'city']);
        });
    }

    /** @param array<string, mixed> $metadata */
    public function recordEvent(AuditResult $result, string $eventType, ?User $actor = null, array $metadata = []): ?AuditResultEvent
    {
        if (! Schema::hasTable('audit_result_events')) {
            return null;
        }

        return AuditResultEvent::query()->create([
            'audit_result_id' => $result->id,
            'audit_rule_id' => $result->audit_rule_id,
            'target_type' => $result->target_type->value,
            'target_key' => $result->target_key,
            'nation_id' => $result->nation_id,
            'city_id' => $result->city_id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function assertOwnsResult(User $actor, AuditResult $result): void
    {
        $nation = $this->eligibilityService->nationFor($actor);

        if ((int) $result->nation_id !== (int) $nation->id) {
            throw new AuthorizationException('The audit finding does not belong to your nation.');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim(strip_tags($note));

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
