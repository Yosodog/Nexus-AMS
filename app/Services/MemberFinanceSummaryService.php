<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Loan;
use App\Models\Nation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class MemberFinanceSummaryService
{
    private const DISBURSED_GRANT_STATUS = 'approved';

    /**
     * @return array{grantTotal: float|null, loanTotal: float|null}
     */
    public function forNation(Nation $nation): array
    {
        $nationId = (int) $nation->getKey();

        return [
            'grantTotal' => $this->lifetimeCashGrantsReceived($nationId),
            'loanTotal' => $this->outstandingLoanPrincipal($nationId),
        ];
    }

    private function lifetimeCashGrantsReceived(int $nationId): ?float
    {
        try {
            $customGrantTotal = GrantApplication::query()
                ->where('nation_id', $nationId)
                ->where('status', self::DISBURSED_GRANT_STATUS)
                ->sum('money');

            $cityGrantTotal = CityGrantRequest::query()
                ->where('nation_id', $nationId)
                ->where('status', self::DISBURSED_GRANT_STATUS)
                ->sum('grant_amount');

            return (float) $customGrantTotal + (float) $cityGrantTotal;
        } catch (QueryException $exception) {
            Log::error('Member dashboard grant total is unavailable.', [
                'nation_id' => $nationId,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function outstandingLoanPrincipal(int $nationId): ?float
    {
        try {
            return (float) Loan::query()
                ->where('nation_id', $nationId)
                ->whereIn('status', LoanStatus::activeValues())
                ->where('remaining_balance', '>', 0)
                ->sum('remaining_balance');
        } catch (QueryException $exception) {
            Log::error('Member dashboard loan total is unavailable.', [
                'nation_id' => $nationId,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
