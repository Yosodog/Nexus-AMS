<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Rebuilding\ApproveRebuildingRequest;
use App\Http\Requests\Admin\Rebuilding\DenyRebuildingRequest;
use App\Http\Requests\Admin\Rebuilding\MarkRebuildingIneligibleRequest;
use App\Http\Requests\Admin\Rebuilding\StoreRebuildingTierRequest;
use App\Http\Requests\Admin\Rebuilding\UpdateRebuildingTierRequest;
use App\Models\Nation;
use App\Models\RebuildingEstimate;
use App\Models\RebuildingIneligibility;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Services\AllianceMembershipService;
use App\Services\AuditLogger;
use App\Services\RebuildingService;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RebuildingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @throws AuthorizationException
     */
    public function index(RebuildingService $rebuildingService): View
    {
        $this->authorize('view-rebuilding');

        $cycleId = $rebuildingService->getCurrentCycleId();
        $allianceIds = app(AllianceMembershipService::class)->getAllianceIds();
        $pending = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('status', 'pending')
            ->with(['nation', 'account', 'tier'])
            ->orderBy('created_at')
            ->get();

        $history = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->whereIn('status', ['approved', 'denied', 'expired'])
            ->with(['nation', 'account', 'tier'])
            ->latest()
            ->paginate(25);

        $tiers = RebuildingTier::query()->orderBy('min_city_count')->get();

        $ineligible = RebuildingIneligibility::query()
            ->where('cycle_id', $cycleId)
            ->with('nation')
            ->latest()
            ->get();

        $estimateTotal = RebuildingEstimate::query()
            ->where('cycle_id', $cycleId)
            ->sum('estimated_amount');
        $estimateCount = RebuildingEstimate::query()
            ->where('cycle_id', $cycleId)
            ->count();
        $totalSentThisCycle = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('status', 'approved')
            ->sum('approved_amount');
        $approvedCount = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('status', 'approved')
            ->count();
        $deniedCount = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->where('status', 'denied')
            ->count();
        $pendingCount = $pending->count();
        $decidedCount = $approvedCount + $deniedCount;
        $approvalRate = $decidedCount > 0 ? ($approvedCount / $decidedCount) * 100 : 0;
        $averageApprovedPayout = $approvedCount > 0 ? ((float) $totalSentThisCycle / $approvedCount) : 0.0;

        $nations = Nation::query()
            ->whereIn('alliance_id', $allianceIds)
            ->select(['id', 'leader_name', 'alliance_position', 'vacation_mode_turns', 'num_cities'])
            ->orderBy('leader_name')
            ->get();

        $estimatesByNation = RebuildingEstimate::query()
            ->where('cycle_id', $cycleId)
            ->whereIn('nation_id', $nations->pluck('id'))
            ->get()
            ->keyBy('nation_id');

        $latestRequestsByNation = RebuildingRequest::query()
            ->where('cycle_id', $cycleId)
            ->whereIn('nation_id', $nations->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('nation_id')
            ->map(fn ($group) => $group->first());

        $ineligibleNationIds = RebuildingIneligibility::query()
            ->where('cycle_id', $cycleId)
            ->pluck('nation_id')
            ->flip();

        $nationRows = $nations->map(function ($nation) use ($estimatesByNation, $latestRequestsByNation, $ineligibleNationIds) {
            $estimate = $estimatesByNation->get($nation->id);
            $latestRequest = $latestRequestsByNation->get($nation->id);
            $status = $latestRequest?->status ?? 'not_applied';

            return [
                'nation_id' => $nation->id,
                'leader_name' => $nation->leader_name,
                'city_count' => (int) ($nation->num_cities ?? 0),
                'estimated_amount' => (float) ($estimate?->estimated_amount ?? 0),
                'status' => $status,
                'is_approved' => $status === 'approved',
                'is_applicant' => ($nation->alliance_position ?? null) === 'APPLICANT',
                'is_vacation_mode' => (int) ($nation->vacation_mode_turns ?? 0) > 0,
                'is_ineligible' => $ineligibleNationIds->has($nation->id),
            ];
        });

        $applicantCount = $nationRows->where('is_applicant', true)->count();
        $vacationCount = $nationRows->where('is_vacation_mode', true)->count();
        $ineligibleCount = $nationRows->where('is_ineligible', true)->count();
        $estimatedButUnsent = max(0, (float) $estimateTotal - (float) $totalSentThisCycle);

        return view('admin.defense.rebuilding', [
            'cycleId' => $cycleId,
            'enabled' => SettingService::isRebuildingEnabled(),
            'tiers' => $tiers,
            'pending' => $pending,
            'history' => $history,
            'ineligible' => $ineligible,
            'estimateTotal' => $estimateTotal,
            'totalSentThisCycle' => $totalSentThisCycle,
            'estimateCount' => $estimateCount,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'deniedCount' => $deniedCount,
            'approvalRate' => $approvalRate,
            'averageApprovedPayout' => $averageApprovedPayout,
            'applicantCount' => $applicantCount,
            'vacationCount' => $vacationCount,
            'ineligibleCount' => $ineligibleCount,
            'estimatedButUnsent' => $estimatedButUnsent,
            'lastEstimateRefreshAt' => SettingService::getRebuildingLastEstimateRefreshAt(),
            'nationRows' => $nationRows,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function toggle(): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        $current = SettingService::isRebuildingEnabled();
        SettingService::setRebuildingEnabled(! $current);

        $this->auditLogger->success(
            category: 'settings',
            action: 'rebuilding_toggle',
            context: [
                'changes' => [
                    'rebuilding_enabled' => [
                        'from' => $current,
                        'to' => ! $current,
                    ],
                ],
            ],
            message: 'Rebuilding toggle updated.'
        );

        return back()->with([
            'alert-message' => 'Rebuilding has been '.($current ? 'disabled' : 'enabled').'.',
            'alert-type' => 'info',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function storeTier(StoreRebuildingTierRequest $request): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        RebuildingTier::query()->create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'requirements' => $request->validated('requirements', []),
        ]);

        return back()->with([
            'alert-message' => 'Rebuilding tier created.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function updateTier(UpdateRebuildingTierRequest $request, RebuildingTier $tier): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        $tier->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'requirements' => $request->validated('requirements', []),
        ]);

        return back()->with([
            'alert-message' => 'Rebuilding tier updated.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroyTier(RebuildingTier $tier): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        $tier->delete();

        return back()->with([
            'alert-message' => 'Rebuilding tier deleted.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function approve(
        ApproveRebuildingRequest $request,
        RebuildingRequest $rebuildingRequest,
        RebuildingService $rebuildingService,
    ): RedirectResponse {
        $this->authorize('manage-rebuilding');

        try {
            $rebuildingService->approveRequest(
                $rebuildingRequest,
                $request->validated('approved_amount'),
                $request->validated('review_note'),
            );
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to approve request.',
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'Rebuilding request approved.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function deny(
        DenyRebuildingRequest $request,
        RebuildingRequest $rebuildingRequest,
        RebuildingService $rebuildingService,
    ): RedirectResponse {
        $this->authorize('manage-rebuilding');

        try {
            $rebuildingService->denyRequest(
                $rebuildingRequest,
                $request->validated('review_note'),
            );
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return back()->with([
                'alert-message' => $details ?: 'Unable to deny request.',
                'alert-type' => 'error',
            ]);
        }

        return back()->with([
            'alert-message' => 'Rebuilding request denied.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function markIneligible(
        MarkRebuildingIneligibleRequest $request,
        RebuildingService $rebuildingService,
    ): RedirectResponse {
        $this->authorize('manage-rebuilding');

        $rebuildingService->markIneligible(
            (int) $request->validated('nation_id'),
            $request->validated('reason'),
        );

        return back()->with([
            'alert-message' => 'Nation marked ineligible.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function clearIneligible(
        int $ineligibilityId,
        RebuildingService $rebuildingService,
    ): RedirectResponse {
        $this->authorize('manage-rebuilding');

        $rebuildingService->clearIneligible($ineligibilityId);

        return back()->with([
            'alert-message' => 'Ineligible flag removed.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function refreshEstimates(RebuildingService $rebuildingService, Request $request): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        $cycleId = $request->filled('cycle_id') ? (int) $request->input('cycle_id') : null;
        $count = $rebuildingService->refreshCycleEstimates($cycleId);

        return back()->with([
            'alert-message' => "Rebuilding estimates refreshed ({$count} records).",
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function resetCycle(RebuildingService $rebuildingService): RedirectResponse
    {
        $this->authorize('manage-rebuilding');

        $newCycle = $rebuildingService->resetCycle();

        return back()->with([
            'alert-message' => "Rebuilding reset. New cycle: {$newCycle}.",
            'alert-type' => 'warning',
        ]);
    }
}
