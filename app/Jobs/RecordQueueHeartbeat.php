<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessHeartbeatRole;
use App\Services\ProcessHeartbeatRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public int $timeout = 15;

    public function uniqueId(): string
    {
        return 'runtime-process-heartbeat:queue';
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['runtime:heartbeat', 'process:queue'];
    }

    public function handle(ProcessHeartbeatRecorder $recorder): void
    {
        $recorder->record(ProcessHeartbeatRole::Queue);
    }
}
