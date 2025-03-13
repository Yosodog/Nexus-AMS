<?php

namespace App\Console\Commands;

use App\Models\Loans;
use App\Services\LoanService;
use Illuminate\Console\Command;

class ProcessLoanPayments extends Command
{
    protected $signature = 'loans:process-payments';
    protected $description = 'Processes all scheduled loan payments for today.';

    protected LoanService $loanService;

    public function __construct(LoanService $loanService)
    {
        parent::__construct();
        $this->loanService = $loanService;
    }

    /**
     * @return void
     */
    public function handle()
    {
        $this->info("Processing all due loan payments...");
        $this->loanService->processWeeklyPayments();
        $this->info("All due loan payments processed successfully!");
    }
}
