<?php

namespace App\Jobs;

use App\Services\TaxBracketService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssignTaxBracket implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected TaxBracketService $taxBracketService;

    /**
     * Create a new job instance.
     */
    public function __construct(TaxBracketService $taxBracketService)
    {
        $this->taxBracketService = $taxBracketService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->taxBracketService->sendAssign();
    }
}
