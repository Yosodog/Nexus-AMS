<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RaidOutcomeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordRaidOutcomeAttackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly int $attackId,
        public readonly int $warId,
    ) {
        $this->onQueue((string) config('raids.queue', 'default'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(RaidOutcomeService $outcomes): void
    {
        $outcomes->recordAttack($this->attackId, $this->warId);
    }
}
