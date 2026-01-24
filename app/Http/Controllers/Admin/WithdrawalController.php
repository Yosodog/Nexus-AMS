<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WithdrawLimit;
use App\Notifications\WithdrawalDeniedNotification;
use App\Services\AccountService;
use App\Services\PendingRequestsService;
use App\Services\PWHelperService;
use App\Services\SelfApprovalGuard;
use App\Services\SettingService;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WithdrawalController extends Controller
{
    public function __construct(private SelfApprovalGuard $selfApprovalGuard) {}

    /**
     * @throws AuthorizationException
     */
    public function index(): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        return redirect()->route('admin.accounts.dashboard');
    }

    /**
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
     * @throws AuthorizationException
     */
    public function approve(Transaction $transaction): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: $transaction->nation_id,
            context: 'approve your own withdrawal request'
        );

        $approvedTransaction = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction
                || ! $lockedTransaction->requires_admin_approval
                || $lockedTransaction->approved_at
                || $lockedTransaction->denied_at) {
                return null;
            }

            $lockedTransaction->requires_admin_approval = false;
            $lockedTransaction->approved_at = now();
            $lockedTransaction->approved_by = auth()->id();
            $lockedTransaction->save();

            return $lockedTransaction;
        });

        if (! $approvedTransaction) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal request is no longer pending.',
                'alert-type' => 'error',
            ]);
        }

        AccountService::dispatchWithdrawal($approvedTransaction);

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal approved and queued for processing.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     * @throws Exception
     */
    public function deny(Request $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: $transaction->nation_id,
            context: 'deny your own withdrawal request'
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $accountName = null;
        $deniedTransaction = null;

        $denied = DB::transaction(function () use ($transaction, $validated, &$accountName, &$deniedTransaction) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction
                || ! $lockedTransaction->requires_admin_approval
                || $lockedTransaction->approved_at
                || $lockedTransaction->denied_at) {
                return false;
            }

            $account = AccountService::getAccountById($lockedTransaction->from_account_id);

            $accountName = $account->name;

            foreach (PWHelperService::resources() as $resource) {
                $account->{$resource} += $lockedTransaction->{$resource};
            }

            $account->save();

            $lockedTransaction->is_pending = false;
            $lockedTransaction->requires_admin_approval = false;
            $lockedTransaction->denied_at = now();
            $lockedTransaction->denied_by = auth()->id();
            $lockedTransaction->denial_reason = $validated['reason'];
            $lockedTransaction->save();

            $deniedTransaction = $lockedTransaction;

            return true;
        });

        if (! $denied || ! $deniedTransaction) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal request is no longer pending.',
                'alert-type' => 'error',
            ]);
        }

        $deniedTransaction->refresh();
        $deniedTransaction->loadMissing('nation', 'fromAccount');

        app(PendingRequestsService::class)->flushCache();

        if ($deniedTransaction->nation) {
            $deniedTransaction->nation->notify(new WithdrawalDeniedNotification(
                nationId: $deniedTransaction->nation_id,
                transaction: $deniedTransaction,
                accountName: $accountName ?? $deniedTransaction->fromAccount?->name,
            ));
        }

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal request denied and funds returned to the account.',
            'alert-type' => 'success',
        ]);
    }
}
