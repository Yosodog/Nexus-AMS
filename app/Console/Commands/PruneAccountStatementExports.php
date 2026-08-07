<?php

namespace App\Console\Commands;

use App\Models\AccountStatementExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('account-statements:prune')]
#[Description('Expire statement downloads and prune old account statement export records')]
class PruneAccountStatementExports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiredCount = 0;
        $failedCount = 0;
        $deletedCount = 0;
        $disk = Storage::disk('local');

        AccountStatementExport::query()
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', AccountStatementExport::STATUS_COMPLETED)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now());
                })->orWhere(function ($query): void {
                    $query->where('status', AccountStatementExport::STATUS_EXPIRED)
                        ->whereNotNull('path');
                });
            })
            ->orderBy('id')
            ->chunkById(250, function ($exports) use ($disk, &$expiredCount): void {
                foreach ($exports as $export) {
                    if (filled($export->path)) {
                        $disk->delete((string) $export->path);
                    }

                    $export->forceFill(['status' => AccountStatementExport::STATUS_EXPIRED, 'path' => null])->save();
                    $expiredCount++;
                }
            });

        $staleCutoff = now()->subHours(max(
            1,
            (int) config('finance.account_statements.stale_processing_hours', 6)
        ));

        AccountStatementExport::query()
            ->whereIn('status', [
                AccountStatementExport::STATUS_PENDING,
                AccountStatementExport::STATUS_PROCESSING,
            ])
            ->where('created_at', '<=', $staleCutoff)
            ->orderBy('id')
            ->chunkById(250, function ($exports) use ($disk, &$failedCount): void {
                foreach ($exports as $export) {
                    $disk->delete([
                        "account-statements/{$export->user_id}/{$export->public_id}.csv.tmp",
                        "account-statements/{$export->user_id}/{$export->public_id}.csv",
                    ]);
                    $export->forceFill([
                        'status' => AccountStatementExport::STATUS_FAILED,
                        'path' => null,
                        'failure_message' => 'The export did not finish. Create a new export.',
                        'failed_at' => now(),
                    ])->save();
                    $failedCount++;
                }
            });

        $retentionCutoff = now()->subDays(max(
            1,
            (int) config('finance.account_statements.history_retention_days', 30)
        ));

        AccountStatementExport::query()
            ->whereIn('status', [
                AccountStatementExport::STATUS_FAILED,
                AccountStatementExport::STATUS_EXPIRED,
            ])
            ->where('updated_at', '<=', $retentionCutoff)
            ->orderBy('id')
            ->chunkById(250, function ($exports) use ($disk, &$deletedCount): void {
                foreach ($exports as $export) {
                    if (filled($export->path)) {
                        $disk->delete((string) $export->path);
                    }

                    $export->delete();
                    $deletedCount++;
                }
            });

        $this->info(
            "Expired {$expiredCount}, failed {$failedCount} stale, and deleted {$deletedCount} old statement exports."
        );

        return self::SUCCESS;
    }
}
