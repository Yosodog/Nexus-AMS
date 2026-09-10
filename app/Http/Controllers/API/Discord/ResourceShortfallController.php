<?php

namespace App\Http\Controllers\API\Discord;

use App\Exceptions\DiscordFinanceException;
use App\Exceptions\UserErrorException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Requests\Discord\DiscordResourceShortfallDraftRequest;
use App\Http\Requests\Discord\DiscordWithdrawalDecisionRequest;
use App\Models\AlertOccurrence;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\MMRAssistantPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Alerts\ResourceShortfallService;
use App\Services\Finance\DiscordResourceShortfallFulfillmentService;
use App\Services\PWHelperService;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceShortfallController extends Controller
{
    public function __construct(
        private readonly DiscordResourceShortfallFulfillmentService $fulfillments,
    ) {}

    public function options(Request $request, AlertOccurrence $occurrence): JsonResponse
    {
        try {
            return $this->success([
                'alert_occurrence_id' => $occurrence->id,
                'target_turns' => ResourceShortfallService::TARGET_TURNS,
                'accounts' => $this->fulfillments->options($this->actor($request), $occurrence),
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function createDraft(
        DiscordResourceShortfallDraftRequest $request,
        AlertOccurrence $occurrence,
    ): JsonResponse {
        try {
            $intent = $this->fulfillments->create(
                $this->actor($request),
                $this->discordAccount($request),
                (string) $request->header(ResolveDiscordActor::GUILD_HEADER),
                (string) $request->header('X-Discord-Interaction-ID'),
                $occurrence,
                (int) $request->validated('account_id'),
            );
            $review = $this->fulfillments->review($this->actor($request), $intent);

            return $this->success([
                'fulfillment' => $this->intentPayload($intent),
                'account' => [
                    'id' => $review['account']->id,
                    'name' => $review['account']->name,
                ],
                'review' => $review['evaluation'],
            ], 201);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function review(Request $request, DiscordActionIntent $intent): JsonResponse
    {
        try {
            $review = $this->fulfillments->review($this->actor($request), $intent);

            return $this->success([
                'fulfillment' => $this->intentPayload($review['intent']),
                'account' => [
                    'id' => $review['account']->id,
                    'name' => $review['account']->name,
                ],
                'review' => $review['evaluation'],
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    public function confirm(
        DiscordWithdrawalDecisionRequest $request,
        DiscordActionIntent $intent,
    ): JsonResponse {
        try {
            $result = $this->fulfillments->confirm($this->actor($request), $intent);
            $intent->refresh();

            return $this->success([
                'fulfillment' => $this->intentPayload($intent),
                'fulfillment_status' => $result['transaction']->requires_admin_approval
                    ? 'pending_review'
                    : 'dispatched',
                'transaction' => $this->transactionPayload($result['transaction']),
                'purchase' => $this->purchasePayload($result['purchase']),
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        } catch (UserErrorException $exception) {
            return $this->error(
                'resource_shortfall_fulfillment_rejected',
                $exception->getMessage(),
                422,
            );
        }
    }

    public function cancel(
        DiscordWithdrawalDecisionRequest $request,
        DiscordActionIntent $intent,
    ): JsonResponse {
        try {
            return $this->success([
                'fulfillment' => $this->intentPayload(
                    $this->fulfillments->cancel($this->actor($request), $intent),
                ),
            ]);
        } catch (DiscordFinanceException $exception) {
            return $this->financeError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function intentPayload(DiscordActionIntent $intent): array
    {
        return [
            'id' => $intent->presentedToken,
            'status' => $intent->status,
            'alert_occurrence_id' => (int) $intent->payload['occurrence_id'],
            'account_id' => (int) $intent->payload['account_id'],
            'mode' => $intent->payload['mode'],
            'resources' => $intent->payload['resources'],
            'purchase_lines' => $intent->payload['purchase_lines'],
            'quoted_total' => $intent->payload['quoted_total'],
            'purchase_is_final' => true,
            'expires_at' => $intent->expires_at->toISOString(),
            'confirmed_at' => $intent->confirmed_at?->toISOString(),
            'canceled_at' => $intent->canceled_at?->toISOString(),
            'transaction_id' => $intent->result_type === Transaction::class ? $intent->result_id : null,
        ];
    }

    /** @return array<string, mixed> */
    private function transactionPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'resources' => collect(PWHelperService::resources())
                ->mapWithKeys(fn (string $resource): array => [
                    $resource => (string) BigDecimal::of((string) $transaction->{$resource})->toScale(2),
                ])
                ->all(),
            'is_pending' => (bool) $transaction->is_pending,
            'requires_admin_approval' => (bool) $transaction->requires_admin_approval,
            'pending_reason' => $transaction->pending_reason,
        ];
    }

    /** @return array<string, mixed>|null */
    private function purchasePayload(?MMRAssistantPurchase $purchase): ?array
    {
        return $purchase === null ? null : [
            'id' => $purchase->id,
            'total_spent' => (string) BigDecimal::of((string) $purchase->total_spent)->toScale(2),
            'allocation_mode' => $purchase->allocation_mode,
        ];
    }

    private function actor(Request $request): User
    {
        return $request->attributes->get(ResolveDiscordActor::ACTOR_ATTRIBUTE);
    }

    private function discordAccount(Request $request): DiscordAccount
    {
        return $request->attributes->get(ResolveDiscordActor::ACCOUNT_ATTRIBUTE);
    }

    private function financeError(DiscordFinanceException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
            'meta' => ['contract_version' => 1],
        ], $exception->httpStatus);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => ['contract_version' => 1],
        ], $status);
    }

    /** @param array<string, mixed> $data */
    private function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'contract_version' => 1,
                'capabilities' => ['alerts.resource-shortfall-actions.v1'],
            ],
        ], $status);
    }
}
