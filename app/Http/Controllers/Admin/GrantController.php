<?php

namespace App\Http\Controllers\Admin;

use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Services\CityGrantService;
use Illuminate\Http\Request;

class GrantController
{

    public function cityGrants()
    {
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

    /**
     * @param  \App\Models\CityGrantRequest  $request
     *
     * @return mixed
     */
    public function approveCityGrant(CityGrantRequest $grantRequest)
    {
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
     * @param  \App\Models\CityGrantRequest  $request
     *
     * @return mixed
     */
    public function denyCityGrant(CityGrantRequest $grantRequest)
    {
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

}