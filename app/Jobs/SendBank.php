<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\BankService;
use App\Services\OffshoreFulfillmentService;
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
    public function handle(OffshoreFulfillmentService $fulfillmentService): void
    {
        // Attempt to top up the main bank before issuing the withdrawal mutation.
        $result = $fulfillmentService->coverShortfall($this->transaction);

        $this->transaction->recordOffshoreFulfillment($result);

        if ($result->shouldSendWithdrawal()) {
            // Safe to proceed—the bank has either enough stock or was topped up successfully.
            $this->bankService->sendWithdraw();

            $this->transaction->setSent();

            return;
        }

        // Something prevented us from fulfilling automatically. Escalate to admins.
        $this->transaction->markPendingAdminReview('Offshore fulfillment failed: '.$result->message);
    }
}
