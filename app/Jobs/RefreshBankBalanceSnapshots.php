<?php

namespace App\Jobs;

use App\Services\MainBankService;
use App\Services\OffshoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshBankBalanceSnapshots implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 3300;

    public function uniqueId(): string
    {
        return 'bank-balance-snapshots';
    }

    public function handle(MainBankService $mainBankService, OffshoreService $offshoreService): void
    {
        $mainBankService->refreshBalances();

        $offshoreService->all()->each(
            fn ($offshore) => $offshoreService->refreshBalances($offshore)
        );
    }
}
