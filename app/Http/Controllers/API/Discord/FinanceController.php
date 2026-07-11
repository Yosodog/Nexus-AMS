<?php

namespace App\Http\Controllers\API\Discord;

use App\Exceptions\DiscordFinanceException;
use App\Exceptions\UserErrorException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Requests\Discord\DiscordDepositRequest;
use App\Http\Requests\Discord\DiscordWithdrawalDecisionRequest;
use App\Http\Requests\Discord\DiscordWithdrawalDraftRequest;
use App\Models\Account;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Finance\DiscordWithdrawalIntentService;
use App\Services\PWHelperService;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FinanceController extends Controller
{
    public function __construct(private readonly DiscordWithdrawalIntentService $withdrawals) {}

    public function accounts(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'account' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $accounts = Account::query()
            ->where('nation_id', $actor->nation_id)
            ->when(isset($filters['account']), fn ($query) => $query->whereKey($filters['account']))
            ->when(isset($filters['query']), fn ($query) => $query->where('name', 'like', '%'.addcslashes($filters['query'], '%_\\').'%'))
            ->orderBy('name')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (Account $account): array => $this->accountPayload($account))
            ->values();

        return $this->success(['accounts' => $accounts]);
    }

    public function transactions(Request $request, Account $account): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $this->assertOwnedAccount($actor, $account);
            $validated = $request->validate([
                'type' => ['nullable', 'in:all,deposit,withdrawal,internal,member-transfer'],
                'status' => ['nullable', 'in:all,pending,completed,failed,needs-attention'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);
            $perPage = (int) ($validated['per_page'] ?? 25);
            $query = Transaction::query()
                ->where(fn ($builder) => $builder->where('to_account_id', $account->id)->orWhere('from_account_id', $account->id))
                ->with(['nation', 'fromAccount', 'toAccount', 'payrollGrade'])
                ->latest();

            $type = $validated['type'] ?? 'all';
            if ($type !== 'all') {
                $query->where('transaction_type', match ($type) {
                    'internal' => 'transfer',
                    'member-transfer' => 'member_transfer',
                    default => $type,
                });
            }

            match ($validated['status'] ?? 'all') {
                'pending' => $query->where('is_pending', true),
                'completed' => $query->where('is_pending', false)->whereNull('denied_at')
                    ->where(fn ($builder) => $builder->whereNull('bank_attempt_status')->orWhereNotIn(
                        'bank_attempt_status',
                        [Transaction::BANK_ATTEMPT_FAILED, Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION],
                    )),
                'failed' => $query->where(fn ($builder) => $builder->whereNotNull('denied_at')->orWhere('bank_attempt_status', Transaction::BANK_ATTEMPT_FAILED)),
                'needs-attention' => $query->where(fn ($builder) => $builder->where('requires_admin_approval', true)
                    ->orWhere('bank_attempt_status', Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION)),
                default => null,
            };

            $transactions = $query->paginate($perPage);

            $items = collect($transactions->items())
                ->map(fn (Transaction $transaction): array => $this->transactionPayload($transaction, $account))
                ->values();

            return $this->success([
                'transactions' => $items,
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function createDepositRequest(DiscordDepositRequest $request, Account $account): JsonResponse
    {
        try {
            $this->assertOwnedAccount($this->actor($request), $account);
            $deposit = AccountService::createDepositRequest($account);

            return $this->success([
                'deposit_request' => [
                    'id' => $deposit->id,
                    'account_id' => $deposit->account_id,
                    'deposit_code' => $deposit->deposit_code,
                    'status' => $deposit->status,
                    'expires_at' => $deposit->expires_at?->toISOString(),
                ],
                'reused' => ! $deposit->wasRecentlyCreated,
            ], $deposit->wasRecentlyCreated ? 201 : 200);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        } catch (UserErrorException $exception) {
            return $this->error('deposit_request_rejected', $exception->getMessage(), 422);
        }
    }

    public function createWithdrawalDraft(DiscordWithdrawalDraftRequest $request): JsonResponse
    {
        try {
            $intent = $this->withdrawals->create(
                $this->actor($request),
                $this->discordAccount($request),
                (string) $request->header(ResolveDiscordActor::GUILD_HEADER),
                (string) $request->header('X-Discord-Interaction-ID'),
                (int) $request->validated('account_id'),
                $request->validated('resources'),
            );
            $review = $this->withdrawals->review($this->actor($request), $intent);

            return $this->success([
                'withdrawal' => $this->intentPayload($intent),
                'review' => $review['evaluation'],
            ], 201);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function reviewWithdrawal(Request $request, DiscordActionIntent $intent): JsonResponse
    {
        try {
            $review = $this->withdrawals->review($this->actor($request), $intent);

            return $this->success([
                'withdrawal' => $this->intentPayload($review['intent']),
                'account' => $this->accountPayload($review['account']),
                'review' => $review['evaluation'],
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function confirmWithdrawal(
        DiscordWithdrawalDecisionRequest $request,
        DiscordActionIntent $intent,
    ): JsonResponse {
        try {
            $transaction = $this->withdrawals->confirm($this->actor($request), $intent);
            $intent->refresh();

            return $this->success([
                'withdrawal' => $this->intentPayload($intent),
                'transaction' => $this->transactionPayload($transaction),
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        } catch (UserErrorException $exception) {
            return $this->error('withdrawal_rejected', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    public function cancelWithdrawal(
        DiscordWithdrawalDecisionRequest $request,
        DiscordActionIntent $intent,
    ): JsonResponse {
        try {
            $intent = $this->withdrawals->cancel($this->actor($request), $intent);

            return $this->success(['withdrawal' => $this->intentPayload($intent)]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    private function actor(Request $request): User
    {
        return $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
    }

    private function discordAccount(Request $request): DiscordAccount
    {
        return $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
    }

    private function assertOwnedAccount(User $actor, Account $account): void
    {
        if ((int) $account->nation_id !== (int) $actor->nation_id) {
            throw new DiscordFinanceException('account_not_found', 'Account not found.', 404);
        }
    }

    private function accountPayload(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'frozen' => (bool) $account->frozen,
            'resources' => $this->resources($account),
        ];
    }

    private function transactionPayload(Transaction $transaction, ?Account $relativeTo = null): array
    {
        $direction = null;
        if ($relativeTo) {
            $direction = (int) $transaction->from_account_id === (int) $relativeTo->id ? 'out' : 'in';
        }

        return [
            'id' => $transaction->id,
            'type' => $transaction->transaction_type,
            'status' => $transaction->requiresBankReconciliation() || $transaction->requires_admin_approval
                ? 'needs-attention'
                : ($transaction->is_pending
                    ? 'pending'
                    : ($transaction->denied_at || $transaction->bank_attempt_status === Transaction::BANK_ATTEMPT_FAILED ? 'failed' : 'completed')),
            'direction' => $direction,
            'from_account_id' => $transaction->from_account_id,
            'to_account_id' => $transaction->to_account_id,
            'resources' => $this->resources($transaction),
            'is_pending' => (bool) $transaction->is_pending,
            'requires_admin_approval' => (bool) $transaction->requires_admin_approval,
            'pending_reason' => $transaction->pending_reason,
            'bank_reconciliation_required' => $transaction->requiresBankReconciliation(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }

    private function intentPayload(DiscordActionIntent $intent): array
    {
        return [
            'id' => $intent->presentedToken,
            'status' => $intent->status,
            'account_id' => (int) $intent->payload['account_id'],
            'resources' => $intent->payload['resources'],
            'expires_at' => $intent->expires_at->toISOString(),
            'confirmed_at' => $intent->confirmed_at?->toISOString(),
            'canceled_at' => $intent->canceled_at?->toISOString(),
            'transaction_id' => $intent->result_type === Transaction::class ? $intent->result_id : null,
        ];
    }

    private function resources(object $model): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (string) BigDecimal::of((string) $model->{$resource})->toScale(2),
            ])
            ->all();
    }

    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['contract_version' => 1],
        ], $status);
    }

    private function financeError(DiscordFinanceException $exception): JsonResponse
    {
        return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $error,
                'message' => $message,
            ],
            'meta' => ['contract_version' => 1],
        ], $status);
    }
}
