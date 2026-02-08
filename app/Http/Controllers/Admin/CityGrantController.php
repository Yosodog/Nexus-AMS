<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SendCityGrantReminderRequest;
use App\Http\Requests\Admin\StoreCityGrantRequest;
use App\Http\Requests\Admin\UpdateCityGrantRequest;
use App\Jobs\SendCityGrantRemindersJob;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Services\AuditLogger;
use App\Services\CityCostService;
use App\Services\CityGrantService;
use App\Services\PWHelperService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CityGrantController
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function cityGrants(): View
    {
        $this->authorize('view-city-grants');

        $pendingRequests = CityGrantRequest::where('status', 'pending')->get();
        $previousRequests = CityGrantRequest::whereIn('status', ['approved', 'denied'])
            ->orderBy('updated_at', 'desc')
            ->get();
        $grants = CityGrant::all();
        $cityCostService = app(CityCostService::class);
        $grantAmounts = $grants->mapWithKeys(
            fn (CityGrant $grant) => [$grant->id => $cityCostService->calculateGrantAmount($grant)]
        );

        // Statistics for info boxes
        $totalApproved = CityGrantRequest::where('status', 'approved')->count();
        $totalDenied = CityGrantRequest::where('status', 'denied')->count();
        $pendingCount = $pendingRequests->count();
        $totalFundsDistributed = CityGrantRequest::where('status', 'approved')->sum('grant_amount');

        return view(
            'admin.grants.cities',
            compact(
                'pendingRequests',
                'previousRequests',
                'grants',
                'grantAmounts',
                'totalApproved',
                'totalDenied',
                'pendingCount',
                'totalFundsDistributed'
            )
        );
    }

    /**ß
     * @param CityGrantRequest $request
     *
     * @return mixed
     * @throws AuthorizationException
     */
    public function approveCityGrant(CityGrantRequest $grantRequest): RedirectResponse
    {
        $this->authorize('manage-city-grants');

        if ($grantRequest->status != 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant is not pending.',
                'alert-type' => 'error',
            ]);
        }

        // Call service to approve grant
        try {
            CityGrantService::approveGrant($grantRequest);
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()->back()->with([
                'alert-message' => $details ?: 'Unable to approve this city grant request.',
                'alert-type' => 'error',
            ]);
        }

        return redirect()->back()->with([
            'alert-message' => "City Grant for City #{$grantRequest->city_number} approved and funds allocated.",
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param  CityGrantRequest  $request
     * @return mixed
     *
     * @throws AuthorizationException
     */
    public function denyCityGrant(CityGrantRequest $grantRequest): RedirectResponse
    {
        $this->authorize('manage-city-grants');

        if ($grantRequest->status !== 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant is not pending.',
                'alert-type' => 'error',
            ]);
        }

        // Call service to deny grant
        CityGrantService::denyGrant($grantRequest);

        return redirect()->back()->with([
            'alert-message' => "City Grant for City #{$grantRequest->city_number} has been denied.",
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Facades\Request  $request
     *
     * @throws AuthorizationException
     */
    public function updateCityGrant(UpdateCityGrantRequest $request, CityGrant $city_grant): RedirectResponse
    {
        $validated = $request->validated();
        $before = $city_grant->only(['city_number', 'grant_amount', 'enabled', 'description', 'requirements']);

        // Convert selected projects to project bits
        $selectedProjects = $validated['projects'] ?? [];
        $projectBits = 0;
        foreach ($selectedProjects as $project) {
            $projectBits |= PWHelperService::PROJECTS[$project];
        }

        // Update city grant details
        $city_grant->update([
            'city_number' => $validated['city_number'],
            'grant_amount' => $validated['grant_amount'],
            'enabled' => $validated['enabled'],
            'description' => $validated['description'],
            'requirements' => [
                'required_projects' => $selectedProjects,
                'project_bits' => $projectBits,
            ],
        ]);

        $after = $city_grant->fresh()->only(['city_number', 'grant_amount', 'enabled', 'description', 'requirements']);
        $changes = [];

        foreach ($after as $field => $value) {
            if ((string) ($before[$field] ?? null) !== (string) $value) {
                $changes[$field] = [
                    'from' => $before[$field] ?? null,
                    'to' => $value,
                ];
            }
        }

        $this->auditLogger->recordAfterCommit(
            category: 'grants',
            action: 'city_grant_updated',
            outcome: 'success',
            severity: 'warning',
            subject: $city_grant,
            context: [
                'changes' => $changes,
            ],
            message: 'City grant updated.'
        );

        return redirect()->route('admin.grants.city')->with('alert-message', 'City grant updated successfully!')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function createCityGrant(StoreCityGrantRequest $request): RedirectResponse
    {
        // TODO this needs to be in the CityGrantService, not here.
        $validated = $request->validated();

        // Convert selected projects to project bits
        $selectedProjects = $validated['projects'] ?? [];
        $projectBits = 0;
        foreach ($selectedProjects as $project) {
            $projectBits |= PWHelperService::PROJECTS[$project];
        }

        // Create new city grant
        $cityGrant = CityGrant::create([
            'city_number' => $validated['city_number'],
            'grant_amount' => $validated['grant_amount'],
            'enabled' => $validated['enabled'],
            'description' => $validated['description'] ?? '',
            'requirements' => [
                'required_projects' => $selectedProjects,
                'project_bits' => $projectBits,
            ],
        ]);

        $this->auditLogger->success(
            category: 'grants',
            action: 'city_grant_created',
            subject: $cityGrant,
            context: [
                'data' => $cityGrant->only(['city_number', 'grant_amount', 'enabled', 'description', 'requirements']),
            ],
            message: 'City grant created.'
        );

        return redirect()->route('admin.grants.city')->with('alert-message', 'City grant created successfully!')->with(
            'alert-type',
            'success'
        );
    }

    public function sendReminders(SendCityGrantReminderRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $grantIds = array_values(array_unique($validated['grant_ids']));

        SendCityGrantRemindersJob::dispatch($grantIds, $validated['message']);

        return redirect()->route('admin.grants.city')->with([
            'alert-message' => 'City grant reminders have been queued for delivery.',
            'alert-type' => 'success',
        ]);
    }
}
