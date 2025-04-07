<?php

namespace App\Http\Controllers;

use App\Models\Grants;
use App\Services\GrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GrantController extends Controller
{
    /**
     * @param Grants $grant
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application|object
     */
    public function show(Grants $grant)
    {
        $nation = Auth::user()->nation;
        $accounts = $nation->accounts ?? [];

        $alreadyApplied = $grant->applications()
            ->where('nation_id', $nation->id)
            ->where('status', 'approved')
            ->exists();

        return view('grants.show_grant', compact('grant', 'accounts', 'alreadyApplied'));
    }

    /**
     * @param Request $request
     * @param Grants $grant
     * @param GrantService $grantService
     * @return \Illuminate\Http\RedirectResponse
     */
    public function apply(Request $request, Grants $grant, GrantService $grantService)
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        try {
            $grantService->applyToGrant(
                Auth::user()->nation,
                $grant,
                $request->input('account_id'),
            );
        } catch (\Throwable $e) {
            return back()->with([
                'alert-message' => $e->getMessage(),
                'alert-type' => 'error'
            ]);
        }

        return back()->with([
            'alert-message' => 'Your grant application has been submitted!',
            'alert-type' => 'success'
        ]);
    }
}
