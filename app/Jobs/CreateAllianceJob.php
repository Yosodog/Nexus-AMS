<?php

namespace App\Jobs;

use App\Models\Alliance;
use App\Services\AllianceQueryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateAllianceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 20;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $allianceId) {}

    public function handle(): void
    {
        $alliance = AllianceQueryService::getAllianceById($this->allianceId);
        Alliance::updateFromAPI($alliance);
    }

    public function uniqueId(): string
    {
        return (string) $this->allianceId;
    }
}
