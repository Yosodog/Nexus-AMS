<?php

namespace App\Jobs;

use App\Exceptions\ProfitabilityContextUnavailable;
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

    public function __construct(
        public readonly int $nationId,
        public readonly ?int $marketPriceSnapshotId = null,
        public readonly ?int $radiationSnapshotId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'nation-profitability-snapshot:'.$this->nationId;
    }

    public function handle(NationProfitabilityService $profitabilityService): void
    {
        try {
            $profitabilityService->refreshStoredSnapshotForNationId(
                $this->nationId,
                $this->marketPriceSnapshotId,
                $this->radiationSnapshotId
            );
        } catch (ProfitabilityContextUnavailable) {
            return;
        }
    }
}
