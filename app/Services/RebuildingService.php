<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Enums\AlliancePositionEnum;
use App\Events\AllianceExpenseOccurred;
use App\Models\AllianceFinanceEntry;
use App\Models\Nation;
use App\Models\RebuildingEstimate;
use App\Models\RebuildingIneligibility;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Notifications\RebuildingNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RebuildingService
{
    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly PWHealthService $healthService,
    ) {}

    public function getCurrentCycleId(): int
    {
        return SettingService::getRebuildingCycleId();
    }

    public function getTierForCityCount(int $cityCount): ?RebuildingTier
    {
        return RebuildingTier::query()
            ->where('is_active', true)
            ->where('min_city_count', '<=', $cityCount)
            ->where(function ($query) use ($cityCount) {
                $query->whereNull('max_city_count')
                    ->orWhere('max_city_count', '>=', $cityCount);
            })
            ->orderByDesc('min_city_count')
            ->first();
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: ?string,
     *     city_count: int,
     *     tier: RebuildingTier|null
     * }
     */
    public function evaluateEligibility(Nation $nation, ?int $cycleId = null): array
    {
        $cycleId ??= $this->getCurrentCycleId();
        $cityCount = $this->resolveCityCount($nation);
        $tier = $this->getTierForCityCount($cityCount);

        if (! $this->membershipService->contains((int) $nation->alliance_id)) {
            return ['eligible' => false, 'reason' => 'Nation is not in alliance scope.', 'city_count' => $cityCount, 'tier' => $tier];
        }

        if (($nation->alliance_position ?? null) === AlliancePositionEnum::APPLICANT->value) {
            return ['eligible' => false, 'reason' => 'Applicants are not eligible for rebuilding.', 'city_count' => $cityCount, 'tier' => $tier];
        }

        if ((int) ($nation->vacation_mode_turns ?? 0) > 0) {
            return ['eligible' => false, 'reason' => 'Vacation mode nations are not eligible for rebuilding.', 'city_count' => $cityCount, 'tier' => $tier];
        }

        if (! $tier) {
            return ['eligible' => false, 'reason' => 'No rebuilding tier configured for your city count.', 'city_count' => $cityCount, 'tier' => null];
        }

        if (RebuildingIneligibility::query()->where('cycle_id', $cycleId)->where('nation_id', $nation->id)->exists()) {
            return ['eligible' => false, 'reason' => 'Nation is marked ineligible for this rebuilding cycle.', 'city_count' => $cityCount, 'tier' => $tier];
        }

        if (RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('nation_id', $nation->id)
            ->where('status', 'approved')
            ->exists()) {
            return ['eligible' => false, 'reason' => 'Nation has already received rebuilding this cycle.', 'city_count' => $cityCount, 'tier' => $tier];
        }

        return ['eligible' => true, 'reason' => null, 'city_count' => $cityCount, 'tier' => $tier];
    }

    public function calculateNationRebuildAmount(Nation $nation, RebuildingTier $tier): float
    {
        $nation->loadMissing('cities');

        $targetInfrastructure = (float) $tier->target_infrastructure;
        $assumed = collect($tier->requirements ?? [])->filter()->values();
        $hasUrban = $assumed->contains('urban_planning') || $assumed->contains('advanced_urban_planning');
        $hasCce = $assumed->contains('center_for_civil_engineering');
        $hasAec = $assumed->contains('advanced_engineering_corps');
        $hasGsa = $assumed->contains('government_support_agency');

        $sum = 0.0;
        foreach ($nation->cities as $city) {
            $start = (float) ($city->infrastructure ?? 0.0);
            if ($targetInfrastructure <= $start) {
                continue;
            }

            $sum += $this->healthService->calcInfra($start, $targetInfrastructure, $hasUrban, $hasCce, $hasAec, $hasGsa);
        }

        return round($sum, 2);
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: ?string,
     *     city_count: int,
     *     tier: RebuildingTier|null,
     *     amount: float
     * }
     */
    public function buildNationEstimate(Nation $nation, ?int $cycleId = null): array
    {
        $cycleId ??= $this->getCurrentCycleId();
        $eligibility = $this->evaluateEligibility($nation, $cycleId);
        $amount = 0.0;

        if ($eligibility['eligible'] && $eligibility['tier']) {
            $amount = $this->calculateNationRebuildAmount($nation, $eligibility['tier']);
        }

        return [
            ...$eligibility,
            'amount' => $amount,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function submitRequest(Nation $nation, array $data): RebuildingRequest
    {
        $cycleId = $this->getCurrentCycleId();

        if (! SettingService::isRebuildingEnabled()) {
            throw ValidationException::withMessages([
                'rebuilding' => 'Rebuilding is currently closed.',
            ]);
        }

        $eligibility = $this->buildNationEstimate($nation, $cycleId);
        if (! $eligibility['eligible'] || ! $eligibility['tier']) {
            throw ValidationException::withMessages([
                'rebuilding' => $eligibility['reason'] ?? 'You are not eligible for rebuilding.',
            ]);
        }

        if (RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('nation_id', $nation->id)
            ->where('status', 'pending')
            ->exists()) {
            throw ValidationException::withMessages([
                'rebuilding' => 'You already have a pending rebuilding request.',
            ]);
        }

        $account = $nation->accounts()->findOrFail((int) $data['account_id']);

        $request = RebuildingRequest::query()->create([
            'cycle_id' => $cycleId,
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'tier_id' => $eligibility['tier']->id,
            'city_count_snapshot' => $eligibility['city_count'],
            'target_infrastructure_snapshot' => $eligibility['tier']->target_infrastructure,
            'estimated_amount' => $eligibility['amount'],
            'status' => 'pending',
            'note' => $data['note'] ?? null,
        ]);

        app(PendingRequestsService::class)->flushCache();

        return $request;
    }

    /**
     * @throws ValidationException
     */
    public function approveRequest(RebuildingRequest $request, ?float $overrideAmount = null, ?string $reviewNote = null): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'request' => 'Only pending requests can be approved.',
            ]);
        }

        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'approve your own rebuilding request'
        );

        $nation = $request->nation()->firstOrFail();
        $eligibility = $this->evaluateEligibility($nation, $request->cycle_id);
        if (! $eligibility['eligible']) {
            throw ValidationException::withMessages([
                'request' => $eligibility['reason'] ?? 'Nation is no longer eligible.',
            ]);
        }

        $approvedAmount = max(0, (int) round($overrideAmount ?? $request->estimated_amount));

        DB::transaction(function () use ($request, $approvedAmount, $reviewNote) {
            $request->update([
                'approved_amount' => $approvedAmount,
                'review_note' => $reviewNote,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            AccountService::adjustAccountBalance(
                account: $request->account,
                resources: [
                    'money' => $approvedAmount,
                    'note' => 'Approved rebuilding request ID #'.$request->id,
                ],
                adminId: auth()->id(),
                ipAddress: request()->ip()
            );

            $request->nation->notify(new RebuildingNotification($request->nation_id, $request->fresh('account'), 'approved'));
        });

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'rebuilding',
            action: 'rebuilding_approved',
            outcome: 'success',
            severity: 'info',
            subject: $request,
            context: [
                'data' => [
                    'nation_id' => $request->nation_id,
                    'amount' => $approvedAmount,
                ],
            ],
            message: 'Rebuilding request approved.'
        );

        $this->dispatchRebuildingExpenseEvent($request->fresh(), $approvedAmount);
    }

    /**
     * @throws ValidationException
     */
    public function denyRequest(RebuildingRequest $request, ?string $reviewNote = null): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'request' => 'Only pending requests can be denied.',
            ]);
        }

        app(SelfApprovalGuard::class)->ensureNotSelf(
            requestNationId: $request->nation_id,
            context: 'deny your own rebuilding request'
        );

        $request->update([
            'review_note' => $reviewNote,
            'status' => 'denied',
            'denied_at' => now(),
            'denied_by' => auth()->id(),
        ]);

        $request->nation->notify(new RebuildingNotification($request->nation_id, $request->fresh('account'), 'denied'));
        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->recordAfterCommit(
            category: 'rebuilding',
            action: 'rebuilding_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $request,
            context: [
                'data' => [
                    'nation_id' => $request->nation_id,
                ],
            ],
            message: 'Rebuilding request denied.'
        );
    }

    public function markIneligible(int $nationId, ?string $reason = null): RebuildingIneligibility
    {
        $cycleId = $this->getCurrentCycleId();
        $record = RebuildingIneligibility::query()->updateOrCreate(
            [
                'cycle_id' => $cycleId,
                'nation_id' => $nationId,
            ],
            [
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]
        );

        RebuildingEstimate::query()
            ->where('cycle_id', $cycleId)
            ->where('nation_id', $nationId)
            ->delete();

        app(AuditLogger::class)->success(
            category: 'rebuilding',
            action: 'rebuilding_marked_ineligible',
            subject: $record,
            context: [
                'data' => [
                    'nation_id' => $nationId,
                    'cycle_id' => $record->cycle_id,
                    'reason' => $reason,
                ],
            ],
            message: 'Nation marked ineligible for rebuilding.'
        );

        return $record;
    }

    public function clearIneligible(int $ineligibilityId): void
    {
        $record = RebuildingIneligibility::query()->findOrFail($ineligibilityId);
        $cycleId = $record->cycle_id;
        $nationId = $record->nation_id;
        $record->delete();

        $nation = Nation::query()->with('cities')->find($nationId);
        if ($nation) {
            $estimate = $this->buildNationEstimate($nation, $cycleId);
            if ($estimate['eligible'] && $estimate['tier']) {
                RebuildingEstimate::query()->updateOrCreate(
                    [
                        'cycle_id' => $cycleId,
                        'nation_id' => $nationId,
                    ],
                    [
                        'city_count' => $estimate['city_count'],
                        'tier_id' => $estimate['tier']->id,
                        'target_infrastructure' => $estimate['tier']->target_infrastructure,
                        'estimated_amount' => $estimate['amount'],
                        'calculated_at' => now(),
                    ]
                );
            }
        }

        app(AuditLogger::class)->success(
            category: 'rebuilding',
            action: 'rebuilding_ineligible_cleared',
            context: [
                'data' => [
                    'nation_id' => $record->nation_id,
                    'cycle_id' => $record->cycle_id,
                ],
            ],
            message: 'Rebuilding ineligibility removed.'
        );
    }

    public function resetCycle(): int
    {
        $cycleId = $this->getCurrentCycleId();

        RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

        SettingService::setRebuildingEnabled(false);
        $newCycleId = SettingService::incrementRebuildingCycleId();

        app(PendingRequestsService::class)->flushCache();

        app(AuditLogger::class)->success(
            category: 'rebuilding',
            action: 'rebuilding_cycle_reset',
            context: [
                'data' => [
                    'from_cycle' => $cycleId,
                    'to_cycle' => $newCycleId,
                ],
            ],
            message: 'Rebuilding cycle reset.'
        );

        return $newCycleId;
    }

    public function refreshCycleEstimates(?int $cycleId = null): int
    {
        $cycleId ??= $this->getCurrentCycleId();
        $allianceIds = $this->membershipService->getAllianceIds();

        RebuildingEstimate::query()->where('cycle_id', $cycleId)->delete();

        $rows = [];
        $calculatedAt = now();
        Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->with('cities')
            ->chunkById(200, function (Collection $nations) use (&$rows, $cycleId, $calculatedAt) {
                foreach ($nations as $nation) {
                    $estimate = $this->buildNationEstimate($nation, $cycleId);

                    if (! $estimate['eligible'] || ! $estimate['tier']) {
                        continue;
                    }

                    $rows[] = [
                        'cycle_id' => $cycleId,
                        'nation_id' => $nation->id,
                        'city_count' => $estimate['city_count'],
                        'tier_id' => $estimate['tier']->id,
                        'target_infrastructure' => $estimate['tier']->target_infrastructure,
                        'estimated_amount' => $estimate['amount'],
                        'calculated_at' => $calculatedAt,
                        'created_at' => $calculatedAt,
                        'updated_at' => $calculatedAt,
                    ];
                }

                if (! empty($rows)) {
                    RebuildingEstimate::query()->insert($rows);
                    $rows = [];
                }
            });

        SettingService::setRebuildingLastEstimateRefreshAt(now());

        return RebuildingEstimate::query()->where('cycle_id', $cycleId)->count();
    }

    public function countPending(): int
    {
        return RebuildingRequest::query()
            ->where('cycle_id', $this->getCurrentCycleId())
            ->where('status', 'pending')
            ->count();
    }

    private function resolveCityCount(Nation $nation): int
    {
        $count = (int) ($nation->num_cities ?? 0);

        if ($count <= 0) {
            $count = $nation->cities()->count();
        }

        return max($count, 0);
    }

    private function dispatchRebuildingExpenseEvent(RebuildingRequest $request, int $amount): void
    {
        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_EXPENSE,
            category: 'rebuilding',
            description: "Rebuilding approved for Nation #{$request->nation_id}",
            date: now(),
            nationId: $request->nation_id,
            accountId: $request->account_id,
            source: $request,
            money: $amount,
            meta: [
                'rebuilding_request_id' => $request->id,
            ],
        );

        event(new AllianceExpenseOccurred($financeData->toArray()));
    }
}
