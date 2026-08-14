<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\AuditResult;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\RebuildingRequest;
use App\Models\WarAidRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MemberDashboardAttentionService
{
    private const READINESS_STALE_AFTER_HOURS = 36;

    /**
     * @param  array<string, mixed>  $dashboardData
     * @return array{attentionItems: list<array<string, mixed>>, attentionCount: int}
     */
    public function forNation(Nation $nation, array $dashboardData): array
    {
        $items = collect([
            $this->overdueLoanItem($nation),
            $this->auditItem($nation),
            $this->readinessItem($nation, $dashboardData),
            $this->pendingRequestsItem($nation),
        ])->filter()->sortByDesc('urgency')->values();

        return [
            'attentionItems' => $items->all(),
            'attentionCount' => $items->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function overdueLoanItem(Nation $nation): ?array
    {
        $loans = Loan::query()
            ->where('nation_id', $nation->getKey())
            ->where('status', LoanStatus::Missed->value)
            ->get(['id', 'past_due_amount', 'accrued_interest_due', 'updated_at']);

        if ($loans->isEmpty()) {
            return null;
        }

        $amountDue = $loans->sum(
            fn (Loan $loan): float => max(0, (float) $loan->past_due_amount)
                + max(0, (float) $loan->accrued_interest_due)
        );

        return [
            'id' => 'overdue-loans',
            'urgency' => 100,
            'intent' => 'failure',
            'label' => 'Overdue',
            'icon' => 'o-exclamation-triangle',
            'title' => $loans->count() === 1 ? 'A loan payment is overdue' : $loans->count().' loan payments are overdue',
            'description' => $amountDue > 0
                ? '$'.number_format($amountDue, 2).' is currently past due, including accrued interest.'
                : 'Review the missed payment and make a payment or contact finance staff.',
            'action' => 'Review loans',
            'url' => route('loans.index'),
            'updated_at' => $this->latestTimestamp($loans),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditItem(Nation $nation): ?array
    {
        $findings = AuditResult::query()
            ->current()
            ->where('nation_id', $nation->getKey())
            ->where(function ($query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('waived_until')->orWhere('waived_until', '<=', now());
            })
            ->get(['id', 'due_at', 'last_evaluated_at', 'updated_at']);

        if ($findings->isEmpty()) {
            return null;
        }

        $overdueCount = $findings->filter(
            fn (AuditResult $result): bool => $result->due_at !== null && $result->due_at->isPast()
        )->count();

        return [
            'id' => 'audit-remediation',
            'urgency' => $overdueCount > 0 ? 95 : 90,
            'intent' => $overdueCount > 0 ? 'failure' : 'warning',
            'label' => $overdueCount > 0 ? 'Overdue' : 'Action needed',
            'icon' => 'o-clipboard-document-check',
            'title' => $findings->count() === 1
                ? 'Resolve 1 audit finding'
                : 'Resolve '.$findings->count().' audit findings',
            'description' => $overdueCount > 0
                ? $overdueCount.' '.($overdueCount === 1 ? 'finding is' : 'findings are').' past the remediation date.'
                : 'Review the findings, expected values, and remediation guidance.',
            'action' => 'Review audit',
            'url' => route('audit.index'),
            'updated_at' => $this->latestTimestamp($findings, 'last_evaluated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboardData
     * @return array<string, mixed>|null
     */
    private function readinessItem(Nation $nation, array $dashboardData): ?array
    {
        $latestSignIn = $dashboardData['latestSignIn'] ?? null;

        if ($latestSignIn === null) {
            return [
                'id' => 'readiness-sync',
                'urgency' => 80,
                'intent' => 'warning',
                'label' => 'Waiting for data',
                'icon' => 'o-arrow-path',
                'title' => 'Readiness data has not synced yet',
                'description' => 'Nexus cannot evaluate your current units and stockpile until the first nation snapshot arrives.',
                'action' => 'View readiness',
                'url' => route('user.dashboard').'#readiness-heading',
                'updated_at' => $nation->updated_at,
            ];
        }

        $snapshotAt = $latestSignIn->created_at instanceof Carbon
            ? $latestSignIn->created_at
            : Carbon::parse($latestSignIn->created_at);

        if ($snapshotAt->lte(now()->subHours(self::READINESS_STALE_AFTER_HOURS))) {
            return [
                'id' => 'readiness-stale',
                'urgency' => 85,
                'intent' => 'warning',
                'label' => 'Data stale',
                'icon' => 'o-clock',
                'title' => 'Readiness data needs a fresh sync',
                'description' => 'The latest readiness snapshot is more than 36 hours old. Verify your nation in Politics & War and contact staff if Nexus remains stale.',
                'action' => 'View nation on P&W',
                'url' => 'https://politicsandwar.com/nation/id='.$nation->getKey(),
                'external' => true,
                'updated_at' => $snapshotAt,
            ];
        }

        if (($dashboardData['mmrResourcesMet'] ?? false) && ($dashboardData['mmrUnitsMet'] ?? false)) {
            return null;
        }

        return [
            'id' => 'readiness-requirements',
            'urgency' => 75,
            'intent' => 'warning',
            'label' => 'Action needed',
            'icon' => 'o-shield-exclamation',
            'title' => 'Bring readiness up to the current target',
            'description' => 'One or more military-unit or resource-stockpile requirements are below the current city-tier target.',
            'action' => 'Review requirements',
            'url' => route('user.dashboard').'#readiness-detail',
            'updated_at' => $snapshotAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingRequestsItem(Nation $nation): ?array
    {
        $nationId = $nation->getKey();
        $customGrants = GrantApplication::query()
            ->with('grant:id,slug')
            ->where('nation_id', $nationId)
            ->where('status', 'pending')
            ->get(['id', 'grant_id', 'updated_at']);
        $cityGrants = CityGrantRequest::query()
            ->where('nation_id', $nationId)
            ->where('status', 'pending')
            ->get(['id', 'updated_at']);
        $loans = Loan::query()
            ->where('nation_id', $nationId)
            ->where('status', LoanStatus::Pending->value)
            ->get(['id', 'updated_at']);
        $warAid = WarAidRequest::query()
            ->where('nation_id', $nationId)
            ->where('status', 'pending')
            ->get(['id', 'updated_at']);
        $rebuilding = RebuildingRequest::query()
            ->where('nation_id', $nationId)
            ->where('status', 'pending')
            ->get(['id', 'updated_at']);
        $collections = collect([$customGrants, $cityGrants, $loans, $warAid, $rebuilding]);
        $count = $collections->sum(fn (Collection $requests): int => $requests->count());

        if ($count === 0) {
            return null;
        }

        $labels = collect([
            $customGrants->isNotEmpty() ? 'custom grant' : null,
            $cityGrants->isNotEmpty() ? 'city grant' : null,
            $loans->isNotEmpty() ? 'loan' : null,
            $warAid->isNotEmpty() ? 'war aid' : null,
            $rebuilding->isNotEmpty() ? 'rebuilding aid' : null,
        ])->filter()->implode(', ');
        $url = match (true) {
            $loans->isNotEmpty() => route('loans.index'),
            $cityGrants->isNotEmpty() => route('grants.city'),
            $warAid->isNotEmpty() => route('defense.war-aid'),
            $rebuilding->isNotEmpty() => route('defense.rebuilding'),
            $customGrants->first()?->grant !== null => route('grants.show_grants', $customGrants->first()->grant),
            default => route('user.dashboard'),
        };

        return [
            'id' => 'pending-requests',
            'urgency' => 40,
            'intent' => 'pending',
            'label' => 'Awaiting review',
            'icon' => 'o-clock',
            'title' => $count === 1 ? '1 request is awaiting staff review' : $count.' requests are awaiting staff review',
            'description' => 'Pending: '.$labels.'. No resubmission is needed while staff reviews the request.',
            'action' => 'View request area',
            'url' => $url,
            'updated_at' => $this->latestTimestamp($collections->flatten(1)),
        ];
    }

    private function latestTimestamp(Collection $models, string $column = 'updated_at'): ?Carbon
    {
        return $models
            ->map(fn ($model) => $model->{$column} ?? $model->updated_at ?? null)
            ->filter()
            ->sortDesc()
            ->first();
    }
}
