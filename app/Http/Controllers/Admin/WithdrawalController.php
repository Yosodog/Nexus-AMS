<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WithdrawLimit;
use App\Services\AccountService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\WithdrawalLimitService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WithdrawalController extends Controller
{
    /**
     * @return View
     * @throws AuthorizationException
     */
    public function index(): View
    {
        $this->authorize('manage-accounts');

        $limits = WithdrawalLimitService::limits();
        $resources = PWHelperService::resources();

        $pendingWithdrawals = Transaction::query()
            ->with(['fromAccount.nation', 'fromAccount.user', 'nation'])
            ->where('transaction_type', 'withdrawal')
            ->where('requires_admin_approval', true)
            ->whereNull('approved_at')
            ->whereNull('denied_at')
            ->orderBy('created_at')
            ->get();

        return view('admin.withdrawals.index', [
            'limits' => $limits,
            'resources' => $resources,
            'pendingWithdrawals' => $pendingWithdrawals,
            'maxDailyWithdrawals' => SettingService::getWithdrawMaxDailyCount(),
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function updateLimits(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');

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

        return redirect()->route('admin.withdrawals.index')->with([
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
        $this->authorize('manage-accounts');

        if (!$transaction->requires_admin_approval || $transaction->approved_at || $transaction->denied_at) {
            return redirect()->route('admin.withdrawals.index')->with([
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

        return redirect()->route('admin.withdrawals.index')->with([
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
        $this->authorize('manage-accounts');

        if (!$transaction->requires_admin_approval || $transaction->approved_at || $transaction->denied_at) {
            return redirect()->route('admin.withdrawals.index')->with([
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

        return redirect()->route('admin.withdrawals.index')->with([
            'alert-message' => 'Withdrawal request denied and funds returned to the account.',
            'alert-type' => 'success',
        ]);
    }
}
