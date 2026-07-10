<?php

namespace App\Services;

use App\DataTransferObjects\AllianceFinanceData;
use App\Events\AllianceIncomeOccurred;
use App\Exceptions\UserErrorException;
use App\GraphQL\Models\BankRecord;
use App\Models\Account;
use App\Models\AllianceFinanceEntry;
use App\Models\DirectDepositEnrollment;
use App\Models\DirectDepositLog;
use App\Models\DirectDepositTaxBracket;
use App\Models\GrowthCircleEnrollment;
use App\Models\Nation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DirectDepositService
{
    public int $ddTaxId;

    public function __construct(
        protected SettingService $settings,
        protected AccountService $accountService,
    ) {
        $this->ddTaxId = SettingService::getDirectDepositId();
    }

    public function process(BankRecord $record): BankRecord
    {
        if ($record->tax_id != $this->ddTaxId) {
            return $record; // Not a DD tax record
        }

        $existingLog = DirectDepositLog::query()
            ->where('bank_record_id', $record->id)
            ->first();

        if ($existingLog) {
            return $this->returnRetainedTaxRecord($record, $existingLog);
        }

        $nation = Nation::find($record->sender_id);
        if (! $nation) {
            Log::warning("DirectDeposit: Nation not found for BankRecord ID {$record->id}");

            return $record;
        }

        $bracket = $this->getApplicableBracket($nation);
        if (! $bracket) {
            $fallbackTaxId = SettingService::getDirectDepositFallbackId();
            Log::warning(
                "DirectDeposit: No tax bracket configured for nation {$nation->id}; using fallback tax ID {$fallbackTaxId}"
            );

            // Bail out and let the standard tax bracket handle this record.
            $record->tax_id = $fallbackTaxId;

            return $record;
        }
        $ddAccount = $this->getDepositAccount($nation);
        $fields = PWHelperService::resources();

        $deposit = []; // after-tax to member
        $retained = []; // alliance taxes

        foreach ($fields as $field) {
            $amount = (float) $record->$field;
            $rate = DirectDepositTaxBracket::normalizeTaxRate((float) $bracket->$field);

            $taxed = round($amount * ($rate / 100), 2);
            $kept = round($amount - $taxed, 2);

            $retained[$field] = $taxed;
            $deposit[$field] = $kept;
        }

        // Preserve the original after-tax numbers for the DD log (pre‑MMR)
        $originalDeposit = $deposit;

        // ---- MMR: compute plan from after-tax cash (money only)
        $afterTaxCash = (float) ($originalDeposit['money'] ?? 0.0);
        $mmrAssistant = app(MMRAssistantService::class);
        $plan = $mmrAssistant->plan($nation, $afterTaxCash);

        $mmrTotalSpend = (float) ($plan['total_spend'] ?? 0.0);
        $mmrAccount = $plan['account'];

        // Reduce the member's actual cash deposit by the MMR spend
        if ($mmrTotalSpend > 0.0) {
            $deposit['money'] = max(0.0, round($deposit['money'] - $mmrTotalSpend, 2));
        }

        $recordedAt = Carbon::parse($record->date, 'UTC')->utc();

        try {
            DB::transaction(function () use ($nation, $record, $deposit, $originalDeposit, $mmrTotalSpend, $plan, $mmrAccount, $mmrAssistant, $ddAccount, $recordedAt) {
                $accountIds = [$ddAccount->id];
                if ($mmrTotalSpend > 0.0 && $mmrAccount) {
                    $accountIds[] = $mmrAccount->id;
                }
                $accountIds = array_values(array_unique($accountIds));
                sort($accountIds);

                $lockedAccounts = Account::query()
                    ->whereKey($accountIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedAccount = $lockedAccounts->get($ddAccount->id);

                if (! $this->isUsableDepositAccount($lockedAccount, $nation)) {
                    throw new RuntimeException("DD account {$ddAccount->id} not found for nation {$nation->id}");
                }

                foreach ($deposit as $resource => $amount) {
                    $lockedAccount->{$resource} += $amount;
                }
                $lockedAccount->save();

                $log = new DirectDepositLog([
                    'nation_id' => $nation->id,
                    'account_id' => $lockedAccount->id,
                    'bank_record_id' => $record->id,
                    ...$originalDeposit,
                ]);
                $log->forceFill(['created_at' => $recordedAt]);
                $log->save();

                if ($mmrTotalSpend > 0.0) {
                    $lockedMmrAccount = $mmrAccount
                        ? $lockedAccounts->get($mmrAccount->id)
                        : null;

                    if (! $this->isUsableDepositAccount($lockedMmrAccount, $nation)) {
                        throw new RuntimeException("MMR account not found for nation {$nation->id}");
                    }

                    $mmrAssistant->applyPlan($lockedMmrAccount, $plan);
                    $this->dispatchMmrContributionEvent($nation, $lockedMmrAccount, $mmrTotalSpend, $record, $log, $plan);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingLog = DirectDepositLog::query()
                ->where('bank_record_id', $record->id)
                ->first();

            if ($existingLog) {
                return $this->returnRetainedTaxRecord($record, $existingLog);
            }

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('DirectDeposit: failed to persist deposit', [
                'bank_record_id' => $record->id,
                'nation_id' => $nation->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        // IMPORTANT: Return the *retained* (tax) values for the tax record
        foreach ($fields as $field) {
            $record->$field = $retained[$field];
        }

        return $record;
    }

    private function returnRetainedTaxRecord(BankRecord $record, DirectDepositLog $log): BankRecord
    {
        foreach (PWHelperService::resources() as $field) {
            $record->{$field} = max(0.0, round((float) $record->{$field} - (float) $log->{$field}, 2));
        }

        return $record;
    }

    /**
     * Determine which tax bracket applies to a nation by city count.
     */
    public function getApplicableBracket(Nation $nation): ?DirectDepositTaxBracket
    {
        return DirectDepositTaxBracket::query()
            ->whereIn('city_number', [(int) $nation->num_cities, 0])
            ->orderByRaw('city_number = ? desc', [(int) $nation->num_cities])
            ->first();
    }

    /**
     * Get the account used for DD deposits or fallback/create a new one.
     */
    public function getDepositAccount(Nation $nation): Account
    {
        $enrollment = DirectDepositEnrollment::query()
            ->with('account')
            ->where('nation_id', $nation->id)
            ->first();

        if ($enrollment && $this->isUsableDepositAccount($enrollment->account, $nation)) {
            return $enrollment->account;
        }

        if ($enrollment) {
            $enrollment->delete();
        }

        $fallback = Account::query()
            ->where('nation_id', $nation->id)
            ->where('frozen', false)
            ->orderBy('id')
            ->first();

        if ($fallback) {
            return $fallback;
        }

        return $this->accountService->createDefaultForNation($nation);
    }

    private function dispatchMmrContributionEvent(
        Nation $nation,
        ?Account $mmrAccount,
        float $mmrSpend,
        BankRecord $record,
        DirectDepositLog $log,
        array $plan
    ): void {
        if ($mmrSpend <= 0.0) {
            return;
        }

        $financeData = new AllianceFinanceData(
            direction: AllianceFinanceEntry::DIRECTION_INCOME,
            category: 'mmr_income',
            description: "MMR Assistant withholding for {$nation->nation_name}",
            date: Carbon::parse($record->date),
            nationId: $nation->id,
            accountId: $mmrAccount?->id,
            source: $log,
            money: $mmrSpend,
            meta: [
                'bank_record_id' => $record->id,
                'plan' => $plan['lines'] ?? [],
            ]
        );

        event(new AllianceIncomeOccurred($financeData->toArray()));
    }

    public function enroll(Nation $nation, Account $account): void
    {
        $ddTaxId = $this->ddTaxId;

        DB::transaction(function () use ($nation, $account, $ddTaxId): void {
            $lockedNation = Nation::query()->whereKey($nation->id)->lockForUpdate()->first();
            if (! $lockedNation) {
                throw new UserErrorException('Nation was not found.');
            }

            if (GrowthCircleEnrollment::query()->where('nation_id', $lockedNation->id)->exists()) {
                throw new UserErrorException(
                    'You are currently enrolled in Growth Circles. Contact an admin to disenroll before joining DirectDeposit.'
                );
            }

            $lockedAccount = Account::query()
                ->whereKey($account->id)
                ->where('nation_id', $lockedNation->id)
                ->where('frozen', false)
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount) {
                throw new UserErrorException('Select an active account that belongs to your nation.');
            }

            $previousTaxId = ((int) $lockedNation->tax_id === $ddTaxId)
                ? SettingService::getDirectDepositFallbackId()
                : (int) $lockedNation->tax_id;

            DirectDepositEnrollment::query()->updateOrCreate(
                ['nation_id' => $lockedNation->id],
                [
                    'account_id' => $lockedAccount->id,
                    'previous_tax_id' => $previousTaxId,
                    'enrolled_at' => now(),
                ]
            );
        });

        $mutation = new TaxBracketService;
        $mutation->id = $ddTaxId;
        $mutation->target_id = $nation->id;
        $mutation->send();
    }

    private function isUsableDepositAccount(?Account $account, Nation $nation): bool
    {
        return $account !== null
            && (int) $account->nation_id === (int) $nation->id
            && ! $account->frozen
            && ! $account->trashed();
    }

    public function disenroll(Nation $nation): void
    {
        $enrollment = DirectDepositEnrollment::where('nation_id', $nation->id)->first();

        if (! $enrollment) {
            return;
        }

        $targetTaxId = $enrollment->previous_tax_id;
        $fallbackTaxId = SettingService::getDirectDepositFallbackId();

        // Attempt to assign the previous tax bracket
        try {
            $mutation = new TaxBracketService;
            $mutation->id = $targetTaxId;
            $mutation->target_id = $nation->id;
            $mutation->send();
        } catch (Throwable $e) {
            // Fallback if failure
            Log::warning(
                "Failed to assign previous tax ID {$targetTaxId} for nation {$nation->id}, retrying with fallback."
            );

            $fallbackMutation = new TaxBracketService;
            $fallbackMutation->id = $fallbackTaxId;
            $fallbackMutation->target_id = $nation->id;
            $fallbackMutation->send();
        }

        // Delete enrollment
        $enrollment->delete();
    }
}
