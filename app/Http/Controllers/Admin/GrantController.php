<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreGrantRequest;
use App\Http\Requests\Admin\UpdateGrantRequest;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    public function createGrant(StoreGrantRequest $request)
    {
        $validated = $request->validated();

        $grant = new Grants;
        $grant->name = $validated['name'];
        $grant->slug = Str::slug($grant->name);
        $grant->description = $validated['description'];
        $grant->money = $validated['money'] ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
            $grant->$resource = $validated[$resource] ?? 0;
        }

        $grant->is_one_time = filter_var($validated['is_one_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($validated['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
    public function updateGrant(Grants $grant, UpdateGrantRequest $request)
    {
        $validated = $request->validated();

        $grant->name = $validated['name'];
        $grant->slug = Str::slug($grant->name);
        $grant->description = $validated['description'];
        $grant->money = $validated['money'] ?? 0;

        foreach (PWHelperService::resources(false) as $resource) {
            $grant->$resource = $validated[$resource] ?? 0;
        }

        $grant->is_one_time = filter_var($validated['is_one_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $grant->is_enabled = filter_var($validated['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
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

        if ($application->status !== 'pending') {
            return redirect()->back()->with([
                'alert-message' => 'Grant application is not pending.',
                'alert-type' => 'error',
            ]);
        }

        try {
            GrantService::approveGrant($application);
        } catch (ValidationException $exception) {
            $details = collect($exception->errors())->flatten()->implode(' ');

            return redirect()->back()->with([
                'alert-message' => $details ?: 'Unable to approve this grant application.',
                'alert-type' => 'error',
            ]);
        }

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
