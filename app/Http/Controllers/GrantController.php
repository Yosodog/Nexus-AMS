<?php

namespace App\Http\Controllers;

use App\Models\Grants;
use App\Services\GrantService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class GrantController extends Controller
{
    /**
     * @param Grants $grant
     * @return Factory|View|Application|object
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
     * @return RedirectResponse
     */
    public function apply(Request $request, Grants $grant)
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $user = Auth::user();
        $nation = $user->nation;

        // Ensure the account belongs to the user's nation
        $accountId = $request->input('account_id');
        $ownsAccount = $nation->accounts()->where('id', $accountId)->exists();

        if (!$ownsAccount) {
            return back()->with([
                'alert-message' => 'You do not own the selected account.',
                'alert-type' => 'error'
            ]);
        }

        try {
            GrantService::applyToGrant($grant, $nation, $accountId);
        } catch (Throwable $e) {
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
