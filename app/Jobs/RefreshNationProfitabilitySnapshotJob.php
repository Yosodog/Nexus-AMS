<?php

namespace App\Jobs;

use App\Services\NationProfitabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshNationProfitabilitySnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(private readonly int $nationId) {}

    public function uniqueId(): string
    {
        return 'nation-profitability-snapshot:'.$this->nationId;
    }

    public function handle(NationProfitabilityService $profitabilityService): void
    {
        $profitabilityService->refreshStoredSnapshotForNationId($this->nationId);
    }
}
