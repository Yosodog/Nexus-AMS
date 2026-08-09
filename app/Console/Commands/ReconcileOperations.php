<?php

namespace App\Console\Commands;

use App\Services\StaffWorkQueue\OperationsCoordinationReconciler;
use App\Services\StaffWorkQueue\StaffWorkQueueRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('operations:reconcile
    {source? : Reconcile one configured Operations source}
    {--force : Refresh the source projection before reconciling}')]
#[Description('Reconcile Operations coordination occurrences, expiries, and terminal work')]
final class ReconcileOperations extends Command
{
    public function handle(
        StaffWorkQueueRegistry $registry,
        OperationsCoordinationReconciler $reconciler,
    ): int {
        $source = $this->argument('source');

        try {
            $snapshots = $registry->reconciliationSnapshots(
                is_string($source) && $source !== '' ? $source : null,
                (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $rows = [];

        foreach ($snapshots as $sourceType => $snapshot) {
            $result = $reconciler->reconcile($snapshot);
            $rows[] = [
                $sourceType,
                $result['discovered'],
                $result['changed'],
                $result['reopened'],
                $result['closed'],
                $result['expired'],
                $result['skipped_closure'] ? 'yes' : 'no',
            ];
        }

        $this->table(
            ['Source', 'Discovered', 'Changed', 'Reopened', 'Closed', 'Expired', 'Closure skipped'],
            $rows,
        );

        return self::SUCCESS;
    }
}
