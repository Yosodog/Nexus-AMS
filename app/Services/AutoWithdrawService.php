<?php

namespace App\Services;

use App\Exceptions\PWQueryFailedException;
use App\Exceptions\PWRateLimitHitException;
use App\Models\Account;
use App\Models\AutoWithdrawSetting;
use App\Models\Nation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutoWithdrawService
{
    public function getNationSettings(int $nationId): Collection
    {
        return AutoWithdrawSetting::query()
            ->where('nation_id', $nationId)
            ->with('account')
            ->get();
    }

    /**
     * @throws ConnectionException
     * @throws PWQueryFailedException
     * @throws PWRateLimitHitException
     */
    public function evaluateAndExecute(Nation $nation): void
    {
        if (! SettingService::isAutoWithdrawEnabled()) {
            return;
        }

        $resources = $nation->resources;
        $now = Carbon::now();

        $requiresRefresh = is_null($resources)
            || ($resources->updated_at?->lt($now->copy()->subHours(3)) ?? true);

        if ($requiresRefresh) {
            try {
                $graphQLNation = NationQueryService::getNationById($nation->id);
                $nation->updateFromAPI($graphQLNation);
                $nation->refresh();
                $resources = $nation->resources;
            } catch (\Throwable) {
                return;
            }
        }

        if (is_null($resources)) {
            return;
        }

        $settings = AutoWithdrawSetting::query()
            ->where('nation_id', $nation->id)
            ->where('enabled', true)
            ->with('account')
            ->get();

        if ($settings->isEmpty()) {
            return;
        }

        $cooldownCutoff = $now->copy()->subDay();
        $accountBatches = [];

        foreach ($settings as $setting) {
            $account = $setting->account;

            if (! $account || $account->nation_id !== $nation->id || $account->frozen) {
                continue;
            }

            if ($setting->last_withdraw_at && $setting->last_withdraw_at->greaterThan($cooldownCutoff)) {
                continue;
            }

            $resourceName = $setting->resource;

            if (! in_array($resourceName, PWHelperService::resources(false), true)) {
                continue;
            }

            $currentAmount = (int) floor($resources->{$resourceName} ?? 0);

            if ($currentAmount >= (int) $setting->threshold) {
                continue;
            }

            $available = (int) floor($account->{$resourceName});
            $amountToWithdraw = min((int) $setting->withdraw_amount, $available);

            if ($amountToWithdraw <= 0) {
                continue;
            }

            $accountBatches[$account->id]['account'] = $account;
            $accountBatches[$account->id]['items'][] = [
                'setting' => $setting,
                'resource' => $resourceName,
                'amount' => $amountToWithdraw,
            ];
        }

        foreach ($accountBatches as $batch) {
            $account = $batch['account'];
            $items = $batch['items'] ?? [];

            if (empty($items)) {
                continue;
            }

            $transaction = null;
            $lockedAccount = null;
            $evaluation = null;

            DB::transaction(function () use (
                &$transaction,
                &$lockedAccount,
                &$evaluation,
                $nation,
                $account,
                $items,
                $now
            ) {
                $lockedAccount = Account::query()
                    ->lockForUpdate()
                    ->findOrFail($account->id);

                $resourcePayload = collect(PWHelperService::resources())->mapWithKeys(
                    fn (string $resource) => [$resource => 0]
                )->toArray();

                $noteParts = [];

                foreach ($items as $item) {
                    $resource = $item['resource'];
                    $available = (int) floor($lockedAccount->{$resource});
                    $amount = min((int) $item['amount'], $available);

                    if ($amount <= 0) {
                        continue;
                    }

                    $lockedAccount->{$resource} -= $amount;
                    $resourcePayload[$resource] = $amount;
                    $noteParts[] = $resource.'='.$amount;

                    $item['setting']->last_withdraw_at = $now;
                    $item['setting']->save();
                }

                if (collect($resourcePayload)->sum() <= 0) {
                    return;
                }

                $lockedAccount->save();

                $evaluation = WithdrawalLimitService::evaluate($nation->id, $resourcePayload);
                $note = 'Withdraw: '.implode(', ', $noteParts);

                $transaction = TransactionService::createTransaction(
                    $resourcePayload,
                    $nation->id,
                    $lockedAccount->id,
                    'withdrawal',
                    null,
                    true,
                    $note,
                    $evaluation['requires_approval'],
                    $evaluation['pending_reason']
                );
            });

            if ($transaction && $lockedAccount && $evaluation && ! $evaluation['requires_approval']) {
                AccountService::dispatchWithdrawal($transaction, $lockedAccount);
            }
        }
    }

    public function updateSetting(
        int $nationId,
        string $resource,
        int $accountId,
        int $threshold,
        int $withdrawAmount,
        bool $enabled
    ): AutoWithdrawSetting {
        if (! in_array($resource, PWHelperService::resources(false), true)) {
            throw new \InvalidArgumentException("Invalid resource {$resource}.");
        }

        $account = Account::query()
            ->where('nation_id', $nationId)
            ->findOrFail($accountId);

        return AutoWithdrawSetting::updateOrCreate(
            [
                'nation_id' => $nationId,
                'resource' => $resource,
            ],
            [
                'account_id' => $account->id,
                'threshold' => max(0, $threshold),
                'withdraw_amount' => max(0, $withdrawAmount),
                'enabled' => $enabled,
            ]
        );
    }
}
