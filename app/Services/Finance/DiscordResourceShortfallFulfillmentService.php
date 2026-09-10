<?php

namespace App\Services\Finance;

use App\Exceptions\DiscordFinanceException;
use App\Models\Account;
use App\Models\AlertOccurrence;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\MMRAssistantPurchase;
use App\Models\MMRSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Alerts\ResourceShortfallService;
use App\Services\MMRAssistantService;
use App\Services\PWHelperService;
use App\Services\SettingService;
use App\Services\TradePriceService;
use App\Services\TransactionService;
use App\Services\WithdrawalLimitService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class DiscordResourceShortfallFulfillmentService
{
    public const ACTION = 'finance.resource_shortfall_fulfillment';

    public const INTENT_TTL_SECONDS = 900;

    public function __construct(
        private readonly ResourceShortfallService $shortfalls,
        private readonly TradePriceService $prices,
        private readonly MMRAssistantService $mmrAssistant,
    ) {}

    /** @return list<array<string, mixed>> */
    public function options(User $actor, AlertOccurrence $occurrence): array
    {
        $this->ownedOccurrence($actor, $occurrence);
        $projection = $this->currentProjection($actor);

        if (TransactionService::hasPendingTransaction((int) $actor->nation_id)) {
            return [];
        }

        return collect($this->buildOptions($actor, $projection))
            ->map(fn (array $option): array => Arr::except($option, ['account_fingerprint']))
            ->all();
    }

    public function create(
        User $actor,
        DiscordAccount $discordAccount,
        string $guildId,
        string $interactionId,
        AlertOccurrence $occurrence,
        int $accountId,
    ): DiscordActionIntent {
        $this->ownedOccurrence($actor, $occurrence);
        $projection = $this->currentProjection($actor);
        $option = collect($this->buildOptions($actor, $projection))
            ->firstWhere('account_id', $accountId);
        if (! is_array($option)) {
            throw new DiscordFinanceException(
                'resource_shortfall_account_unavailable',
                'That account can no longer fund the complete resource withdrawal.',
                409,
            );
        }

        $token = bin2hex(random_bytes(32));
        $intent = DiscordActionIntent::query()->create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $actor->id,
            'discord_account_id' => $discordAccount->id,
            'discord_user_id' => $discordAccount->discord_id,
            'guild_id' => $guildId,
            'action' => self::ACTION,
            'payload' => [
                'occurrence_id' => $occurrence->id,
                'account_id' => $accountId,
                'mode' => $option['mode'],
                'resources' => $projection['resources'],
                'projection_calculated_at' => $projection['calculated_at']->utc()->toISOString(),
                'resource_snapshot_at' => $projection['resource_snapshot_at']->utc()->toISOString(),
                'resource_snapshot_fingerprint' => $projection['snapshot_fingerprint'],
                'account_fingerprint' => $option['account_fingerprint'],
                'purchase_lines' => $option['purchase_lines'],
                'quoted_total' => $option['quoted_total'],
            ],
            'status' => DiscordActionIntent::STATUS_DRAFT,
            'created_interaction_id' => $interactionId,
            'expires_at' => now()->addSeconds(max(
                60,
                (int) config('services.discord.resource_shortfall_intent_ttl_seconds', self::INTENT_TTL_SECONDS),
            )),
        ]);
        $intent->presentedToken = $token;

        return $intent;
    }

    /** @return array{intent:DiscordActionIntent,account:Account,evaluation:array<string,mixed>} */
    public function review(User $actor, DiscordActionIntent $intent): array
    {
        $intent = $this->ownedIntent($actor, $intent);
        $account = $this->ownedAccount($actor, (int) $intent->payload['account_id']);
        if ($intent->status === DiscordActionIntent::STATUS_DRAFT) {
            $this->assertIntentIsCurrent($actor, $intent, $account);
        }

        return [
            'intent' => $intent,
            'account' => $account,
            'evaluation' => WithdrawalLimitService::evaluate(
                (int) $actor->nation_id,
                $intent->payload['resources'],
            ),
        ];
    }

    /** @return array{transaction:Transaction,purchase:?MMRAssistantPurchase} */
    public function confirm(User $actor, DiscordActionIntent $intent): array
    {
        return Cache::lock("discord-action-intent:{$intent->token_hash}", 15)
            ->block(5, function () use ($actor, $intent): array {
                $presentedToken = $intent->presentedToken;
                $intent = $this->ownedIntent($actor, $intent->fresh());
                $intent->presentedToken = $presentedToken;

                if ($intent->status === DiscordActionIntent::STATUS_CONFIRMED) {
                    return [
                        'transaction' => $intent->transaction()->firstOrFail(),
                        'purchase' => MMRAssistantPurchase::query()
                            ->where('discord_action_intent_id', $intent->id)
                            ->first(),
                    ];
                }

                if ($intent->status !== DiscordActionIntent::STATUS_DRAFT) {
                    throw new DiscordFinanceException(
                        'resource_shortfall_intent_not_confirmable',
                        'This resource shortfall action can no longer be confirmed.',
                        409,
                    );
                }

                $account = $this->ownedAccount($actor, (int) $intent->payload['account_id']);
                $this->assertIntentIsCurrent($actor, $intent, $account);
                $purchase = null;
                $transaction = AccountService::transferToNationForActor(
                    $actor,
                    $account->id,
                    $intent->payload['resources'],
                    $intent,
                    function (Account $lockedAccount) use ($actor, $intent, &$purchase): void {
                        $this->assertIntentIsCurrent($actor, $intent, $lockedAccount);
                        $purchaseLines = (array) ($intent->payload['purchase_lines'] ?? []);
                        if ($purchaseLines === []) {
                            return;
                        }

                        $this->assertMmrPurchaseIsAvailable($purchaseLines);
                        try {
                            $purchase = $this->mmrAssistant->applyAlertOnDemandPurchase(
                                $lockedAccount,
                                $intent,
                                $purchaseLines,
                                (string) $intent->payload['quoted_total'],
                                Carbon::parse((string) $intent->payload['projection_calculated_at']),
                            );
                        } catch (InvalidArgumentException $exception) {
                            throw new DiscordFinanceException(
                                'resource_shortfall_purchase_rejected',
                                $exception->getMessage(),
                                422,
                            );
                        }
                    },
                    function (Transaction $transaction, DiscordActionIntent $lockedIntent): void {
                        $lockedIntent->forceFill([
                            'status' => DiscordActionIntent::STATUS_CONFIRMED,
                            'confirmed_at' => now(),
                            'result_type' => Transaction::class,
                            'result_id' => $transaction->id,
                        ])->save();
                    },
                );

                return [
                    'transaction' => $transaction,
                    'purchase' => $purchase ?? MMRAssistantPurchase::query()
                        ->where('discord_action_intent_id', $intent->id)
                        ->first(),
                ];
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
                        'resource_shortfall_intent_not_cancelable',
                        'This resource shortfall action can no longer be canceled.',
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

    /**
     * @param  array<string, mixed>  $projection
     * @return list<array<string, mixed>>
     */
    public function buildOptions(User $actor, array $projection): array
    {
        $mmrEnabled = SettingService::getMMRAssistantEnabled();
        $resourceSettings = $mmrEnabled
            ? MMRSetting::query()->get()->keyBy('resource')
            : collect();
        $prices = $mmrEnabled ? $this->prices->get24hAverageWithSurcharge() : [];

        return Account::query()
            ->where('nation_id', $actor->nation_id)
            ->where('frozen', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): ?array => $this->buildAccountOption(
                $account,
                $projection,
                $mmrEnabled,
                $resourceSettings,
                $prices,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $projection
     * @param  Collection<string, MMRSetting>  $resourceSettings
     * @param  array<string, float|int>  $prices
     * @return array<string, mixed>|null
     */
    private function buildAccountOption(
        Account $account,
        array $projection,
        bool $mmrEnabled,
        Collection $resourceSettings,
        array $prices,
    ): ?array {
        $purchaseLines = [];
        $total = BigDecimal::zero();

        foreach (PWHelperService::resources(false) as $resource) {
            $required = BigDecimal::of((string) ($projection['resources'][$resource] ?? '0.00'));
            if ($required->isZero()) {
                continue;
            }

            $available = BigDecimal::of((string) ($account->{$resource} ?? '0.00'));
            $missing = $required->minus($available);
            if ($missing->isLessThanOrEqualTo(0)) {
                continue;
            }

            $setting = $resourceSettings->get($resource);
            $ppu = BigDecimal::of((string) ($prices[$resource] ?? '0.00'));
            if (! $mmrEnabled || ! $setting?->enabled || $ppu->isLessThanOrEqualTo(0)) {
                return null;
            }

            $quantity = $missing->toScale(2, RoundingMode::Ceiling);
            $spend = $quantity->multipliedBy($ppu)->toScale(2, RoundingMode::Ceiling);
            $purchaseLines[$resource] = [
                'qty' => (string) $quantity,
                'ppu' => (string) $ppu->toScale(2, RoundingMode::HalfUp),
                'spend' => (string) $spend,
            ];
            $total = $total->plus($spend);
        }

        $total = $total->toScale(2, RoundingMode::Ceiling);
        if (BigDecimal::of((string) $account->money)->isLessThan($total)) {
            return null;
        }

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'mode' => $purchaseLines === [] ? 'withdrawal' : 'mmr_purchase_withdrawal',
            'withdrawal_resources' => $projection['resources'],
            'purchase_lines' => $purchaseLines,
            'quoted_total' => (string) $total,
            'account_fingerprint' => $this->accountFingerprint($account),
            'review' => WithdrawalLimitService::evaluate((int) $account->nation_id, $projection['resources']),
        ];
    }

    private function assertIntentIsCurrent(User $actor, DiscordActionIntent $intent, Account $account): void
    {
        $projection = $this->currentProjection($actor);
        if (! hash_equals(
            (string) $intent->payload['resource_snapshot_fingerprint'],
            $projection['snapshot_fingerprint'],
        ) || ! hash_equals(
            (string) $intent->payload['account_fingerprint'],
            $this->accountFingerprint($account),
        )) {
            throw new DiscordFinanceException(
                'resource_shortfall_intent_stale',
                'The shortage or account balance changed. Generate a new preview.',
                409,
            );
        }

        $this->ownedOccurrence(
            $actor,
            AlertOccurrence::query()->find((int) $intent->payload['occurrence_id']),
        );
    }

    /** @param array<string, array{qty:string,ppu:string,spend:string}> $purchaseLines */
    private function assertMmrPurchaseIsAvailable(array $purchaseLines): void
    {
        if (! SettingService::getMMRAssistantEnabled()) {
            throw new DiscordFinanceException(
                'mmr_assistant_unavailable',
                'MMR Assistant sales are no longer enabled.',
                409,
            );
        }

        $enabled = MMRSetting::query()
            ->whereIn('resource', array_keys($purchaseLines))
            ->where('enabled', true)
            ->pluck('resource')
            ->all();
        if (count($enabled) !== count($purchaseLines)) {
            throw new DiscordFinanceException(
                'mmr_resource_unavailable',
                'One or more quoted resources are no longer available from MMR Assistant.',
                409,
            );
        }
    }

    /** @return array<string, mixed> */
    private function currentProjection(User $actor): array
    {
        $nation = $actor->nation()->first();
        $projection = $nation === null ? null : $this->shortfalls->project($nation);
        if ($projection === null) {
            throw new DiscordFinanceException(
                'resource_shortfall_resolved',
                'The resource shortfall is resolved or current nation data is unavailable.',
                409,
            );
        }

        return $projection;
    }

    private function ownedOccurrence(User $actor, ?AlertOccurrence $occurrence): AlertOccurrence
    {
        if ($occurrence === null
            || $occurrence->event_key !== ResourceShortfallService::EVENT_KEY
            || (int) $occurrence->audience_user_id !== (int) $actor->id
            || (int) $occurrence->subject_id !== (int) $actor->nation_id
            || ($occurrence->stale_at !== null && $occurrence->stale_at->isPast())) {
            throw new DiscordFinanceException(
                'resource_shortfall_alert_not_found',
                'Resource shortfall alert not found.',
                404,
            );
        }

        return $occurrence;
    }

    private function ownedIntent(User $actor, DiscordActionIntent $intent): DiscordActionIntent
    {
        if ((int) $intent->user_id !== (int) $actor->id || $intent->action !== self::ACTION) {
            throw new DiscordFinanceException(
                'resource_shortfall_intent_not_found',
                'Resource shortfall action not found.',
                404,
            );
        }

        if ($intent->status === DiscordActionIntent::STATUS_DRAFT && $intent->expires_at->isPast()) {
            $intent->forceFill(['status' => DiscordActionIntent::STATUS_EXPIRED])->save();
        }

        return $intent;
    }

    private function ownedAccount(User $actor, int $accountId): Account
    {
        $account = Account::query()->find($accountId);
        if ($account === null || (int) $account->nation_id !== (int) $actor->nation_id) {
            throw new DiscordFinanceException('account_not_found', 'Account not found.', 404);
        }

        if ($account->frozen) {
            throw new DiscordFinanceException('account_frozen', 'This account is frozen.', 422);
        }

        return $account;
    }

    private function accountFingerprint(Account $account): string
    {
        $balances = collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [
                $resource => (string) BigDecimal::of((string) ($account->{$resource} ?? 0))->toScale(2),
            ])
            ->all();

        return hash('sha256', json_encode([
            'account_id' => (int) $account->id,
            'balances' => $balances,
        ], JSON_THROW_ON_ERROR));
    }
}
