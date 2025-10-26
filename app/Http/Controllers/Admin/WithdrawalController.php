<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WithdrawLimit;
use App\Services\AccountService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;

class WithdrawalController extends Controller
{
    /**
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function index(): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        return redirect()->route('admin.accounts.dashboard');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function updateLimits(Request $request): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        $resources = PWHelperService::resources();
        $rules = [
            'limits' => ['required', 'array'],
            'max_daily_withdrawals' => ['required', 'integer', 'min:0'],
        ];

        foreach ($resources as $resource) {
            $rules["limits.$resource"] = ['nullable', 'numeric', 'min:0'];
        }

        $validated = $request->validate($rules);

        foreach ($resources as $resource) {
            $value = $validated['limits'][$resource] ?? 0;

            WithdrawLimit::updateOrCreate(
                ['resource' => $resource],
                ['daily_limit' => $value ?? 0]
            );
        }

        SettingService::setWithdrawMaxDailyCount($validated['max_daily_withdrawals']);

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal limits updated successfully.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param Transaction $transaction
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function approve(Transaction $transaction): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        if (!$transaction->requires_admin_approval || $transaction->approved_at || $transaction->denied_at) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal request is no longer pending.',
                'alert-type' => 'error',
            ]);
        }

        DB::transaction(function () use ($transaction) {
            $transaction->requires_admin_approval = false;
            $transaction->approved_at = now();
            $transaction->approved_by = auth()->id();
            $transaction->save();
        });

        AccountService::dispatchWithdrawal($transaction);

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal approved and queued for processing.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @param Request $request
     * @param Transaction $transaction
     * @return RedirectResponse
     * @throws AuthorizationException
     * @throws Exception
     */
    public function deny(Request $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        if (!$transaction->requires_admin_approval || $transaction->approved_at || $transaction->denied_at) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal request is no longer pending.',
                'alert-type' => 'error',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($transaction, $validated) {
            $account = AccountService::getAccountById($transaction->from_account_id);

            foreach (PWHelperService::resources() as $resource) {
                $account->{$resource} += $transaction->{$resource};
            }

            $account->save();

            $transaction->is_pending = false;
            $transaction->denied_at = now();
            $transaction->denied_by = auth()->id();
            $transaction->denial_reason = $validated['reason'];
            $transaction->save();
        });

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal request denied and funds returned to the account.',
            'alert-type' => 'success',
        ]);
    }
}
