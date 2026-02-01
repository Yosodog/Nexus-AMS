<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\SettingService;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune audit logs older than the configured retention window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = SettingService::getAuditLogRetentionDays();
        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        AuditLog::query()
            ->where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(1000, function ($logs) use (&$deleted) {
                $ids = $logs->pluck('id');

                if ($ids->isEmpty()) {
                    return;
                }

                $deleted += AuditLog::query()->whereKey($ids)->delete();
            });

        $this->info("Pruned {$deleted} audit logs older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
