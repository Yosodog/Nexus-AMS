<?php

namespace App\Jobs;

use App\Domain\Federation\Services\FederatedWarPlanImporter;
use App\Models\FederationReceivedVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportFederatedWarPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly string $receivedVersionId) {}

    public function handle(FederatedWarPlanImporter $importer): void
    {
        $version = FederationReceivedVersion::query()->find($this->receivedVersionId);

        if ($version instanceof FederationReceivedVersion) {
            $importer->import($version);
        }
    }

    public function uniqueId(): string
    {
        return $this->receivedVersionId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }
}
