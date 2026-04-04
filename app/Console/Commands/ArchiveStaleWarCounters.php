<?php

namespace App\Console\Commands;

use App\Models\WarCounter;
use App\Services\War\CounterAssignmentService;
use App\Services\War\NotificationService;
use Illuminate\Console\Command;

class ArchiveStaleWarCounters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'war-counters:archive-stale';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive open war counters older than the configured auto-archive window';

    /**
     * Execute the console command.
     */
    public function handle(CounterAssignmentService $assignmentService, NotificationService $notificationService): int
    {
        $archiveDays = max(1, (int) config('war.counters.room_auto_archive_days', 14));
        $cutoff = now()->subDays($archiveDays);
        $archivedCount = 0;

        WarCounter::query()
            ->whereIn('status', ['draft', 'active'])
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_war_declared_at', '<', $cutoff)
                    ->orWhere(function ($fallbackQuery) use ($cutoff): void {
                        $fallbackQuery->whereNull('last_war_declared_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($counters) use ($assignmentService, $notificationService, &$archivedCount): void {
                foreach ($counters as $counter) {
                    if ($counter->status === 'archived') {
                        continue;
                    }

                    $counter = $assignmentService->archive($counter);
                    $notificationService->queueCounterArchivedRoomNotification($counter);
                    $archivedCount++;
                }
            });

        $this->info("Archived {$archivedCount} stale war counters.");

        return self::SUCCESS;
    }
}
