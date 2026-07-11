<?php

namespace App\Services\Finance;

use App\Exceptions\DiscordFinanceException;
use App\Models\Account;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\PWHelperService;
use App\Services\TransactionService;
use App\Services\WithdrawalLimitService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;

class DiscordWithdrawalIntentService
{
    public const ACTION = 'finance.withdrawal';

    public function create(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        int $accountId,
        array $resources,
    ): DiscordActionIntent {
        $account = $this->ownedAccount($actor, $accountId);
        $resources = $this->normalizeResources($resources);
        $this->validateDraft($account, $resources);

        $token = bin2hex(random_bytes(32));
        $intent = DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $actor->id,
            'discord_account_id' => $discordAccount->id,
            'guild_id' => $guildId,
            'action' => self::ACTION,
            'payload' => [
                'account_id' => $account->id,
                'resources' => $resources,
            ],
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'created_interaction_id' => $interactionId,
            'expires_at' => now()->addSeconds(max(60, (int) config('services.discord.finance_action_intent_ttl_seconds', 120))),
        ]);

        $intent->presentedToken = $token;

        return $intent;
    }

    /**
     * @return array{intent: DiscordActionIntent, account: Account, evaluation: array<string, mixed>}
     */
    public function review(User $actor, DiscordActionIntent $intent): array
    {
        $intent = $this->ownedIntent($actor, $intent);
        $account = $this->ownedAccount($actor, (int) $intent->payload['account_id']);

        if ($intent->status === DiscordActionIntent::STATUS_DRAFT) {
            $this->validateDraft($account, $intent->payload['resources']);
        }

        return [
            'intent' => $intent,
            'account' => $account,
            'evaluation' => WithdrawalLimitService::evaluate((int) $actor->nation_id, $intent->payload['resources']),
        ];
    }

    public function confirm(User $actor, DiscordActionIntent $intent): Transaction
    {
        return Cache::lock("discord-action-intent:{$intent->token_hash}", 15)
            ->block(5, function () use ($actor, $intent): Transaction {
                $presentedToken = $intent->presentedToken;
                $intent = $this->ownedIntent($actor, $intent->fresh());
                $intent->presentedToken = $presentedToken;

                if ($intent->status === DiscordActionIntent::STATUS_CONFIRMED) {
                    return $intent->transaction()->firstOrFail();
                }

                if ($intent->status !== DiscordActionIntent::STATUS_DRAFT) {
                    throw new DiscordFinanceException(
                        'withdrawal_intent_not_confirmable',
                        'This withdrawal intent can no longer be confirmed.',
                        409,
                    );
                }

                $transaction = AccountService::transferToNationForActor(
                    $actor,
                    (int) $intent->payload['account_id'],
                    $intent->payload['resources'],
                    $intent,
                );

                $intent->forceFill([
                    'status' => DiscordActionIntent::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'result_type' => Transaction::class,
                    'result_id' => $transaction->id,
                ])->save();

                return $transaction;
            });
    }

    public function cancel(User $actor, DiscordActionIntent $intent): DiscordActionIntent
    {
        return Cache::lock("discord-action-intent:{$intent->token_hash}", 15)
            ->block(5, function () use ($actor, $intent): DiscordActionIntent {
                $presentedToken = $intent->presentedToken;
                $intent = $this->ownedIntent($actor, $intent->fresh());
                $intent->presentedToken = $presentedToken;

                if ($intent->status === DiscordActionIntent::STATUS_CANCELED) {
                    return $intent;
                }

                if ($intent->status !== DiscordActionIntent::STATUS_DRAFT) {
                    throw new DiscordFinanceException(
                        'withdrawal_intent_not_cancelable',
                        'This withdrawal intent can no longer be canceled.',
                        409,
                    );
                }

                $intent->forceFill([
                    'status' => DiscordActionIntent::STATUS_CANCELED,
                    'canceled_at' => now(),
                ])->save();

                return $intent;
            });
    }

    private function ownedIntent(User $actor, DiscordActionIntent $intent): DiscordActionIntent
    {
        if ($intent->user_id !== $actor->id || $intent->action !== self::ACTION) {
            throw new DiscordFinanceException('withdrawal_intent_not_found', 'Withdrawal intent not found.', 404);
        }

        if ($intent->status === DiscordActionIntent::STATUS_DRAFT && $intent->expires_at->isPast()) {
            $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
        }

        return $intent;
    }

    private function ownedAccount(User $actor, int $accountId): Account
    {
        $account = Account::query()->find($accountId);

        if (! $account || (int) $account->nation_id !== (int) $actor->nation_id) {
            throw new DiscordFinanceException('account_not_found', 'Account not found.', 404);
        }

        return $account;
    }

    private function validateDraft(Account $account, array $resources): void
    {
        if ($account->frozen) {
            throw new DiscordFinanceException('account_frozen', 'This account is frozen.', 422);
        }

        if (TransactionService::hasPendingTransaction((int) $account->nation_id)) {
            throw new DiscordFinanceException(
                'pending_transaction_exists',
                'Only one pending transaction is allowed at a time.',
                409,
            );
        }

        $hasResources = false;
        foreach ($resources as $resource => $amount) {
            if (bccomp($amount, (string) $account->{$resource}, 2) > 0) {
                throw new DiscordFinanceException(
                    'insufficient_account_balance',
                    "Insufficient {$resource} in the source account.",
                    422,
                );
            }

            $hasResources = $hasResources || bccomp($amount, '0.00', 2) > 0;
        }

        if (! $hasResources) {
            throw new DiscordFinanceException('empty_withdrawal', 'A withdrawal must include at least one resource.', 422);
        }
    }

    /**
     * @return array<string, string>
     */
    private function normalizeResources(array $input): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (string) BigDecimal::of((string) $input[$resource])->toScale(2),
            ])
            ->all();
    }
}
