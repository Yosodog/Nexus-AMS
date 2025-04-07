<?php

namespace App\Http\Controllers\Admin;

use App\Models\Accounts;
use App\Models\GrantApplications;
use App\Models\Grants;
use App\Notifications\GrantNotification;
use App\Services\AccountService;
use App\Services\GrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestAlias;
use Illuminate\Support\Str;

class GrantController
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     */
    public function grants()
    {
        $grants = Grants::orderBy('created_at', 'desc')->get();
        $pendingRequests = GrantApplications::with('grant', 'nation', 'account')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalApproved = GrantApplications::where('status', 'approved')->count();
        $totalDenied = GrantApplications::where('status', 'denied')->count();
        $pendingCount = $pendingRequests->count();
        $totalFundsDistributed = GrantApplications::where('status', 'approved')->sum('money');

        return view('admin.grants.grants', compact(
            'grants',
            'pendingRequests',
            'totalApproved',
            'totalDenied',
            'pendingCount',
            'totalFundsDistributed'
        ));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createGrant(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:grants,name',
            'description' => 'nullable|string',
            'money' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|in:true,false,1,0,on,off',
            'is_one_time' => 'nullable|in:true,false,1,0,on,off',
        ]);

        $grant = new Grants();
        $grant->name = $request->input('name');
        $grant->slug = Str::slug($grant->name);
        $grant->description = $request->input('description');
        $grant->money = $request->input('money') ?? 0;

        foreach (['coal','oil','uranium','iron','bauxite','lead','gasoline','munitions','steel','aluminum','food'] as $resource) {
            $grant->$resource = $request->input($resource, 0);
        }

        $grant->is_one_time = filter_var($request->input('is_one_time', false), FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($request->input('is_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $grant->save();

        return redirect()->route('admin.grants')
            ->with('alert-message', 'Grant created successfully.')
            ->with('alert-type', 'success');
    }

    /**
     * @param Grants $grant
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateGrant(Grants $grant, Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:grants,name,' . $grant->id,
            'description' => 'nullable|string',
            'money' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|in:true,false,1,0,on,off',
            'is_one_time' => 'nullable|in:true,false,1,0,on,off',
        ]);

        $grant->name = $request->input('name');
        $grant->slug = Str::slug($grant->name);
        $grant->description = $request->input('description');
        $grant->money = $request->input('money') ?? 0;

        foreach (['coal','oil','uranium','iron','bauxite','lead','gasoline','munitions','steel','aluminum','food'] as $resource) {
            $grant->$resource = $request->input($resource, 0);
        }

        $grant->is_one_time = filter_var($request->input('is_one_time', false), FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($request->input('is_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $grant->save();

        return redirect()->route('admin.grants')
            ->with('alert-message', 'Grant updated successfully.')
            ->with('alert-type', 'success');
    }

    /**
     * @param GrantApplications $application
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approveApplication(GrantApplications $application)
    {
        GrantService::approveGrant($application);

        return redirect()->back()
            ->with('alert-message', 'Grant approved and funds distributed.')
            ->with('alert-type', 'success');
    }

    /**
     * @param GrantApplications $application
     * @return \Illuminate\Http\RedirectResponse
     */
    public function denyApplication(GrantApplications $application)
    {
        GrantService::denyGrant($application);

        return redirect()->back()
            ->with('alert-message', 'Grant application denied.')
            ->with('alert-type', 'success');
    }
}