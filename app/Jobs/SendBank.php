<?php

namespace App\Jobs;

use App\Services\BankService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBank implements ShouldQueue
{

    use Queueable;

    protected BankService $bankService;

    /**
     * Create a new job instance.
     */
    public function __construct(BankService $bankService)
    {
        $this->bankService = $bankService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->bankService->sendWithdraw();
    }

}
