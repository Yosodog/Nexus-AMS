<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class ProcessLoanPayments extends Command
{
    protected $signature = 'loans:process-payments';

    protected $description = 'Processes all scheduled loan payments for today.';

    public function __construct(private LoanService $loanService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! SettingService::isLoanPaymentsEnabled()) {
            $this->info('Loan payments are currently paused.');

            return self::SUCCESS;
        }

        $this->info('Processing all due loan payments...');
        $this->loanService->processWeeklyPayments();
        $this->info('All due loan payments processed successfully!');

        return self::SUCCESS;
    }
}
