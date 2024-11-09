<?php

namespace App\Jobs;

use App\Models\Transactions;
use App\Services\BankService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBank implements ShouldQueue
{

    use Queueable;

    protected BankService $bankService;
    protected Transactions $transaction;

    /**
     * Create a new job instance.
     */
    public function __construct(BankService $bankService, Transactions $transaction)
    {
        $this->bankService = $bankService;
        $this->transaction = $transaction;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->bankService->sendWithdraw();

        $this->transaction->setSent();
    }

}
