<?php

namespace App\Http\Controllers\Admin;

use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Services\CityGrantService;
use App\Services\PWHelperService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CityGrantController
{
    use AuthorizesRequests;

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function cityGrants()
    {
        $this->authorize('view-city-grants');

        $pendingRequests = CityGrantRequest::where('status', 'pending')->get();
        $previousRequests = CityGrantRequest::whereIn('status', ['approved', 'denied'])
            ->orderBy('updated_at', 'desc')
            ->get();
        $grants = CityGrant::all();

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
    public function approveCityGrant(CityGrantRequest $grantRequest)
    {
        $this->authorize('manage-city-grants');

        if ($grantRequest->status != 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant is not pending.',
                'alert-type' => 'error',
            ]);
        }

        // Call service to approve grant
        CityGrantService::approveGrant($grantRequest);

        return redirect()->back()->with([
            'alert-message' => "City Grant for City #{$grantRequest->city_number} approved and funds allocated.",
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param CityGrantRequest $request
     *
     * @return mixed
     * @throws AuthorizationException
     */
    public function denyCityGrant(CityGrantRequest $grantRequest)
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
     * @param \Illuminate\Support\Facades\Request $request
     * @param CityGrant $city_grant
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function updateCityGrant(Request $request, CityGrant $city_grant)
    {
        $this->authorize('manage-city-grants');

        $validated = $request->validate([
            'city_number' => 'required|integer|min:1|unique:city_grants,city_number,' . $city_grant->id,
            'grant_amount' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'description' => 'nullable|string|max:255',
            'projects' => 'array',
            'projects.*' => 'string|in:' . implode(',', array_keys(PWHelperService::PROJECTS)),
        ]);

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
            'requirements' => json_encode([
                'required_projects' => $selectedProjects,
                'project_bits' => $projectBits,
            ]),
        ]);

        return redirect()->route('admin.grants.city')->with('alert-message', 'City grant updated successfully!')->with(
            'alert-type',
            'success'
        );
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function createCityGrant(Request $request)
    {
        $this->authorize('manage-city-grants');

        $validated = $request->validate([
            'city_number' => 'required|integer|min:1|unique:city_grants,city_number',
            'grant_amount' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'description' => 'nullable|string|max:255',
            'projects' => 'array',
            'projects.*' => 'string|in:' . implode(',', array_keys(PWHelperService::PROJECTS)),
        ]);

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
            'description' => $validated['description'],
            'requirements' => json_encode([
                'required_projects' => $selectedProjects,
                'project_bits' => $projectBits,
            ]),
        ]);

        return redirect()->route('admin.grants.city')->with('alert-message', 'City grant created successfully!')->with(
            'alert-type',
            'success'
        );
    }

}