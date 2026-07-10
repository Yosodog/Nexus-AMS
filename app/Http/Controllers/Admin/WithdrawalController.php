<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReconcileWithdrawalRequest;
use App\Models\Transaction;
use App\Models\WithdrawLimit;
use App\Notifications\WithdrawalDeniedNotification;
use App\Services\AccountService;
use App\Services\AuditLogger;
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
    public function __construct(
        private SelfApprovalGuard $selfApprovalGuard,
        private AuditLogger $auditLogger,
    ) {}

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
        $previousLimits = WithdrawLimit::query()->pluck('daily_limit', 'resource')->toArray();
        $previousMaxDaily = SettingService::getWithdrawMaxDailyCount();
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

        $changes = [];
        foreach ($resources as $resource) {
            $before = $previousLimits[$resource] ?? 0;
            $after = $validated['limits'][$resource] ?? 0;

            if ((float) $before !== (float) $after) {
                $changes["limits.{$resource}"] = [
                    'from' => $before,
                    'to' => $after,
                ];
            }
        }

        if ($previousMaxDaily !== (int) $validated['max_daily_withdrawals']) {
            $changes['max_daily_withdrawals'] = [
                'from' => $previousMaxDaily,
                'to' => (int) $validated['max_daily_withdrawals'],
            ];
        }

        $this->auditLogger->success(
            category: 'finance',
            action: 'withdrawal_limits_updated',
            context: [
                'changes' => $changes,
                'data' => [
                    'limits' => $validated['limits'],
                    'max_daily_withdrawals' => (int) $validated['max_daily_withdrawals'],
                ],
            ],
            message: 'Withdrawal limits updated.'
        );

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

        if ($transaction->requiresBankReconciliation()) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal may already have been sent. Reconcile the recorded bank attempt instead of approving another send.',
                'alert-type' => 'error',
            ]);
        }

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
                || $lockedTransaction->requiresBankReconciliation()
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

        $this->auditLogger->recordAfterCommit(
            category: 'finance',
            action: 'withdrawal_approved',
            outcome: 'success',
            severity: 'info',
            subject: $approvedTransaction,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $approvedTransaction->from_account_id, 'role' => 'from_account'],
                ],
                'data' => [
                    'nation_id' => $approvedTransaction->nation_id,
                    'resources' => PWHelperService::resources()
                        ? collect(PWHelperService::resources())
                            ->mapWithKeys(fn ($resource) => [$resource => $approvedTransaction->{$resource}])
                            ->all()
                        : [],
                ],
            ],
            message: 'Withdrawal approved.'
        );

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

        if ($transaction->requiresBankReconciliation()) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal may already have been sent. Reconcile the recorded bank attempt before returning any funds.',
                'alert-type' => 'error',
            ]);
        }

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
                || $lockedTransaction->requiresBankReconciliation()
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

        $this->auditLogger->recordAfterCommit(
            category: 'finance',
            action: 'withdrawal_denied',
            outcome: 'denied',
            severity: 'warning',
            subject: $deniedTransaction,
            context: [
                'related' => [
                    ['type' => 'Account', 'id' => (string) $deniedTransaction->from_account_id, 'role' => 'from_account'],
                ],
                'data' => [
                    'nation_id' => $deniedTransaction->nation_id,
                    'reason' => $validated['reason'],
                    'resources' => collect(PWHelperService::resources())
                        ->mapWithKeys(fn ($resource) => [$resource => $deniedTransaction->{$resource}])
                        ->all(),
                ],
            ],
            message: 'Withdrawal denied.'
        );

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => 'Withdrawal request denied and funds returned to the account.',
            'alert-type' => 'success',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function reconcile(ReconcileWithdrawalRequest $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('manage-accounts');

        if (! $transaction->isNationWithdrawal()) {
            abort(403, 'This transaction cannot be reconciled as a withdrawal.');
        }

        $this->selfApprovalGuard->ensureNotSelf(
            requestNationId: $transaction->nation_id,
            context: 'reconcile your own withdrawal request'
        );

        $validated = $request->validated();
        $resolvedTransaction = null;

        $result = DB::transaction(function () use ($request, $transaction, $validated, &$resolvedTransaction): string {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $lockedTransaction || ! $lockedTransaction->requiresBankReconciliation()) {
                return 'not-pending';
            }

            if ($validated['resolution'] === 'confirmed_sent') {
                $bankRecordId = (int) $validated['bank_record_id'];
                $recordAlreadyAssigned = Transaction::query()
                    ->where('bank_record_id', $bankRecordId)
                    ->where('id', '!=', $lockedTransaction->id)
                    ->exists();

                if ($recordAlreadyAssigned) {
                    return 'duplicate-bank-record';
                }

                $lockedTransaction->is_pending = false;
                $lockedTransaction->requires_admin_approval = false;
                $lockedTransaction->bank_processing_at = null;
                $lockedTransaction->sent_at = $lockedTransaction->bank_attempted_at ?? now();
                $lockedTransaction->bank_record_id = $bankRecordId;
                $lockedTransaction->bank_attempt_status = Transaction::BANK_ATTEMPT_RECONCILED_SENT;
            } else {
                if (is_null($lockedTransaction->from_account_id)) {
                    return 'account-missing';
                }

                $fromAccount = AccountService::getAccountById($lockedTransaction->from_account_id);
                $adjustment = collect(PWHelperService::resources())
                    ->mapWithKeys(fn (string $resource): array => [$resource => $lockedTransaction->{$resource}])
                    ->all();
                $adjustment['note'] = "Evidence-based reconciliation refund for Transaction #{$lockedTransaction->id}";

                AccountService::adjustAccountBalance(
                    $fromAccount,
                    $adjustment,
                    auth()->id(),
                    $request->ip(),
                    [
                        'correlation_id' => $lockedTransaction->bank_correlation_id,
                        'withdrawal_transaction_id' => $lockedTransaction->id,
                    ],
                );

                $lockedTransaction->is_pending = false;
                $lockedTransaction->requires_admin_approval = false;
                $lockedTransaction->bank_processing_at = null;
                $lockedTransaction->refunded_at = now();
                $lockedTransaction->bank_attempt_status = Transaction::BANK_ATTEMPT_RECONCILED_REFUNDED;
            }

            $lockedTransaction->bank_reconciliation_details = array_merge(
                $lockedTransaction->bank_reconciliation_details ?? [],
                [
                    'resolution' => $validated['resolution'],
                    'evidence' => $validated['evidence'],
                    'resolved_at' => now()->toISOString(),
                    'resolved_by' => auth()->id(),
                    'bank_record_id' => $validated['bank_record_id'] ?? null,
                ]
            );
            $lockedTransaction->save();

            $this->auditLogger->record(
                category: 'finance',
                action: $validated['resolution'] === 'confirmed_sent'
                    ? 'withdrawal_reconciled_sent'
                    : 'withdrawal_reconciled_refunded',
                outcome: 'success',
                severity: 'warning',
                subject: $lockedTransaction,
                context: [
                    'related' => [
                        ['type' => 'Account', 'id' => (string) $lockedTransaction->from_account_id, 'role' => 'from_account'],
                    ],
                    'data' => [
                        'nation_id' => $lockedTransaction->nation_id,
                        'correlation_id' => $lockedTransaction->bank_correlation_id,
                        'resolution' => $validated['resolution'],
                        'evidence' => $validated['evidence'],
                        'bank_record_id' => $validated['bank_record_id'] ?? null,
                    ],
                ],
                message: 'Ambiguous withdrawal resolved from documented external evidence.'
            );

            $resolvedTransaction = $lockedTransaction;

            return 'resolved';
        });

        if ($result === 'duplicate-bank-record') {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'That Politics & War bank record is already assigned to another transaction.',
                'alert-type' => 'error',
            ]);
        }

        if ($result === 'account-missing') {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'The source account no longer exists, so the withdrawal cannot be refunded automatically.',
                'alert-type' => 'error',
            ]);
        }

        if ($result !== 'resolved' || ! $resolvedTransaction) {
            return redirect()->route('admin.accounts.dashboard')->with([
                'alert-message' => 'This withdrawal no longer requires reconciliation.',
                'alert-type' => 'error',
            ]);
        }

        app(PendingRequestsService::class)->flushCache();

        $message = $validated['resolution'] === 'confirmed_sent'
            ? 'Withdrawal reconciled as sent using the supplied Politics & War bank record.'
            : 'Withdrawal reconciled as not sent and refunded to the source account.';

        return redirect()->route('admin.accounts.dashboard')->with([
            'alert-message' => $message,
            'alert-type' => 'success',
        ]);
    }
}
