<?php

namespace App\Console\Commands;

use App\Services\PayrollService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunDailyPayroll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payroll:run-daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the daily payroll payout process.';

    /**
     * Execute the console command.
     */
    public function handle(PayrollService $payrollService): int
    {
        $runDate = Carbon::now();
        $summary = $payrollService->runDailyPayroll($runDate);

        $message = sprintf(
            'Payroll run complete: %d total, %d paid, %d removed, %d skipped (no account), %d skipped (disabled), %d skipped (other).',
            $summary['total'],
            $summary['paid'],
            $summary['removed'],
            $summary['skipped_no_account'],
            $summary['skipped_disabled'],
            $summary['skipped_other']
        );

        $this->info($message);

        Log::info('Daily payroll run completed', [
            'run_date' => $runDate->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
