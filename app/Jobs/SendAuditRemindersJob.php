<?php

namespace App\Jobs;

use App\Models\AuditResult;
use App\Services\AllianceMemberEligibilityService;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAuditRemindersJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(
        PrivateNotificationService $notifications,
        AllianceMemberEligibilityService $eligibilityService,
    ): void {
        AuditResult::query()
            ->with(['nation.user.discordAccounts'])
            ->where(function ($query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('waived_until')->orWhere('waived_until', '<=', now());
            })
            ->get()
            ->groupBy('nation_id')
            ->each(function ($results, $nationId) use ($notifications, $eligibilityService): void {
                $nation = $results->first()?->nation;

                if (! $nation || ! $eligibilityService->isEligibleNation($nation)) {
                    return;
                }

                $notifications->enqueueForNation(
                    $nation,
                    'audits',
                    'audit_summary_reminder',
                    'audit-reminder:'.$nationId.':'.now()->toDateString(),
                    ['type' => 'audit_summary', 'id' => (int) $nationId, 'label' => 'Audit findings'],
                    '/audit',
                    [
                        'finding_count' => $results->count(),
                        'overdue_count' => $results->filter(fn (AuditResult $result): bool => $result->due_at?->isPast() ?? false)->count(),
                    ],
                );
            });
    }
}
