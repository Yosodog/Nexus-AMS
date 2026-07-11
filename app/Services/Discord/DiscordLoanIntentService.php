<?php

namespace App\Services\Discord;

use App\Models\Account;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscordLoanIntentService
{
    public const APPLICATION_ACTION = 'loan.application';

    public const PAYMENT_ACTION = 'loan.payment';

    public function __construct(private readonly LoanService $loans) {}

    public function previewApplication(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        int $accountId,
        float $amount,
        int $termWeeks,
    ): DiscordActionIntent {
        if (! SettingService::isLoanApplicationsEnabled()) {
            throw ValidationException::withMessages(['loan' => 'Loan applications are currently closed.']);
        }

        $account = $this->ownedAccount($actor, $accountId);
        $this->loans->validateLoanEligibility($actor->nation, $account);

        return $this->createIntent($actor, $discordAccount, $guildId, $interactionId, self::APPLICATION_ACTION, [
            'account_id' => $account->id,
            'amount' => round($amount, 2),
            'term_weeks' => $termWeeks,
        ]);
    }

    public function confirmApplication(User $actor, string $publicId): Loan
    {
        return Cache::lock('discord-action-intent:'.hash('sha256', $publicId), 15)->block(5, function () use ($actor, $publicId): Loan {
            return DB::transaction(function () use ($actor, $publicId): Loan {
                $intent = $this->ownedIntent($actor, $publicId, self::APPLICATION_ACTION, true);
                if ($intent->status === DiscordActionIntent::STATUS_CONFIRMED) {
                    return Loan::query()->findOrFail($intent->result_id);
                }
                $this->assertConfirmable($intent);

                $account = $this->ownedAccount($actor, (int) $intent->payload['account_id']);
                $this->loans->validateLoanEligibility($actor->nation, $account);
                $loan = $this->loans->applyForLoan(
                    $actor->nation,
                    $account,
                    (float) $intent->payload['amount'],
                    (int) $intent->payload['term_weeks'],
                );
                $this->confirmIntent($intent, $loan);

                return $loan;
            }, attempts: 3);
        });
    }

    /** @return array{intent:DiscordActionIntent,loan:Loan,account:Account,breakdown:array<string,float>} */
    public function previewPayment(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        int $loanId,
        int $accountId,
        float $amount,
    ): array {
        if (! SettingService::isLoanPaymentsEnabled()) {
            throw ValidationException::withMessages(['loan' => 'Loan payments are currently paused.']);
        }

        $loan = $this->ownedLoan($actor, $loanId);
        $account = $this->ownedAccount($actor, $accountId);
        $this->validatePayment($loan, $account, $amount);
        $intent = $this->createIntent($actor, $discordAccount, $guildId, $interactionId, self::PAYMENT_ACTION, [
            'loan_id' => $loan->id,
            'account_id' => $account->id,
            'amount' => round($amount, 2),
        ]);

        return [
            'intent' => $intent,
            'loan' => $loan,
            'account' => $account,
            'breakdown' => $this->loans->previewPaymentBreakdown($loan, $amount),
        ];
    }

    public function confirmPayment(User $actor, string $publicId): Loan
    {
        return Cache::lock('discord-action-intent:'.hash('sha256', $publicId), 15)->block(5, function () use ($actor, $publicId): Loan {
            return DB::transaction(function () use ($actor, $publicId): Loan {
                $intent = $this->ownedIntent($actor, $publicId, self::PAYMENT_ACTION, true);
                if ($intent->status === DiscordActionIntent::STATUS_CONFIRMED) {
                    return Loan::query()->findOrFail($intent->result_id);
                }
                $this->assertConfirmable($intent);

                $loan = $this->ownedLoan($actor, (int) $intent->payload['loan_id']);
                $account = $this->ownedAccount($actor, (int) $intent->payload['account_id']);
                $amount = (float) $intent->payload['amount'];
                $this->validatePayment($loan, $account, $amount);
                $this->loans->repayLoan($loan, $account, $amount);
                $loan->refresh();
                $this->confirmIntent($intent, $loan);

                return $loan;
            }, attempts: 3);
        });
    }

    private function createIntent(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        string $action,
        array $payload,
    ): DiscordActionIntent {
        $token = Str::random(64);
        $intent = DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $actor->id,
            'discord_account_id' => $discordAccount->id,
            'guild_id' => $guildId,
            'action' => $action,
            'payload' => $payload,
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'created_interaction_id' => $interactionId,
            'expires_at' => now()->addSeconds(max(60, (int) config('services.discord.workflow_action_intent_ttl_seconds', 900))),
        ]);
        $intent->presentedToken = $token;

        return $intent;
    }

    private function ownedIntent(User $actor, string $publicId, string $action, bool $lock = false): DiscordActionIntent
    {
        $query = DiscordActionIntent::query()
            ->where('token_hash', hash('sha256', $publicId))
            ->where('user_id', $actor->id)
            ->where('guild_id', (string) config('services.discord.guild_id'))
            ->where('action', $action);
        $intent = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($intent->status === DiscordActionIntent::STATUS_DRAFT && $intent->expires_at->isPast()) {
            $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
        }

        return $intent;
    }

    private function assertConfirmable(DiscordActionIntent $intent): void
    {
        if ($intent->status !== DiscordActionIntent::STATUS_DRAFT) {
            throw ValidationException::withMessages(['intent_id' => 'This action intent can no longer be confirmed.']);
        }
    }

    private function confirmIntent(DiscordActionIntent $intent, Loan $loan): void
    {
        $intent->forceFill([
            'status' => DiscordActionIntent::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'result_type' => Loan::class,
            'result_id' => $loan->id,
        ])->save();
    }

    private function ownedAccount(User $actor, int $accountId): Account
    {
        return Account::query()->whereKey($accountId)->where('nation_id', $actor->nation_id)->firstOrFail();
    }

    private function ownedLoan(User $actor, int $loanId): Loan
    {
        return Loan::query()->whereKey($loanId)->where('nation_id', $actor->nation_id)->firstOrFail();
    }

    private function validatePayment(Loan $loan, Account $account, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be greater than zero.']);
        }
        if (! in_array($loan->status, ['approved', 'missed'], true)) {
            throw ValidationException::withMessages(['loan_id' => 'This loan is not in a repayable state.']);
        }
        if ($account->frozen) {
            throw ValidationException::withMessages(['account_id' => 'The selected account is frozen.']);
        }
        if ((float) $account->money < $amount) {
            throw ValidationException::withMessages(['account_id' => 'The selected account has insufficient funds.']);
        }
        $maximum = (float) $loan->remaining_balance + (float) $loan->accrued_interest_due;
        if ($amount > round($maximum, 2)) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the current amount owed.']);
        }
    }
}
