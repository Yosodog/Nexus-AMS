<?php

namespace App\Http\Controllers\Admin;

use App\Models\GrantApplication;
use App\Models\Grants;
use App\Services\GrantService;
use App\Services\PWHelperService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GrantController
{
    use AuthorizesRequests;

    /**
     * @return Factory|View|Application|object
     *
     * @throws AuthorizationException
     */
    public function grants()
    {
        $this->authorize('view-grants');

        $grants = Grants::orderBy('created_at', 'desc')->get();
        $pendingRequests = GrantApplication::with('grant', 'nation', 'account')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalApproved = GrantApplication::where('status', 'approved')->count();
        $totalDenied = GrantApplication::where('status', 'denied')->count();
        $pendingCount = $pendingRequests->count();
        $totalFundsDistributed = GrantApplication::where('status', 'approved')->sum('money');

        return view(
            'admin.grants.grants',
            compact(
                'grants',
                'pendingRequests',
                'totalApproved',
                'totalDenied',
                'pendingCount',
                'totalFundsDistributed'
            )
        );
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function createGrant(Request $request)
    {
        $this->authorize('manage-grants');

        $validated = $request->validate([
            'name' => 'required|string|unique:grants,name',
            'description' => 'nullable|string',
            'money' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|in:true,false,1,0,on,off',
            'is_one_time' => 'nullable|in:true,false,1,0,on,off',
        ]);

        $grant = new Grants;
        $grant->name = $request->input('name');
        $grant->slug = Str::slug($grant->name);
        $grant->description = $request->input('description');
        $grant->money = $request->input('money') ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
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
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function updateGrant(Grants $grant, Request $request)
    {
        $this->authorize('manage-grants');

        $validated = $request->validate([
            'name' => 'required|string|unique:grants,name,'.$grant->id,
            'description' => 'nullable|string',
            'money' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|in:true,false,1,0,on,off',
            'is_one_time' => 'nullable|in:true,false,1,0,on,off',
        ]);

        $grant->name = $request->input('name');
        $grant->slug = Str::slug($grant->name);
        $grant->description = $request->input('description');
        $grant->money = $request->input('money') ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
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
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function approveApplication(GrantApplication $application)
    {
        $this->authorize('manage-grants');

        GrantService::approveGrant($application);

        return redirect()->back()
            ->with('alert-message', 'Grant approved and funds distributed.')
            ->with('alert-type', 'success');
    }

    /**
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function denyApplication(GrantApplication $application)
    {
        $this->authorize('manage-grants');

        GrantService::denyGrant($application);

        return redirect()->back()
            ->with('alert-message', 'Grant application denied.')
            ->with('alert-type', 'success');
    }
}
