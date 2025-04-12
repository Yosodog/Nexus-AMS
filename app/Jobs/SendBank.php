<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\BankService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBank implements ShouldQueue
{

    use Queueable;

    protected BankService $bankService;
    protected Transaction $transaction;

    /**
     * Create a new job instance.
     */
    public function __construct(BankService $bankService, Transaction $transaction)
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
