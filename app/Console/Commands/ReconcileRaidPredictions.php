<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RaidPrediction;
use App\Services\RaidOutcomeService;
use App\Services\RuntimeCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('raid:reconcile-predictions {--war= : Reconcile one war ID} {--days=30 : Reconcile declarations from the last N days}')]
#[Description('Reconcile raid outcome evidence and terminal war states')]
final class ReconcileRaidPredictions extends Command
{
    public function handle(RaidOutcomeService $outcomes, RuntimeCapabilities $capabilities): int
    {
        if (! $capabilities->writesTenantPrivate()) {
            $this->components->info('Raid prediction reconciliation is unavailable in this runtime.');

            return self::SUCCESS;
        }

        $warId = $this->option('war');
        if ($warId !== null && (! is_numeric($warId) || (int) $warId < 1)) {
            $this->components->error('The --war option must be a positive integer.');

            return self::INVALID;
        }

        if ($warId !== null) {
            $outcomes->reconcile((int) $warId);
            $this->components->info('Raid prediction reconciled.');

            return self::SUCCESS;
        }

        $days = $this->option('days');
        if (! is_numeric($days) || (int) $days < 1 || (int) $days > 3650) {
            $this->components->error('The --days option must be between 1 and 3650.');

            return self::INVALID;
        }

        $cutoff = CarbonImmutable::now()->subDays((int) $days);
        $count = 0;
        $skipped = 0;
        RaidPrediction::query()
            ->where('declared_at', '>=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($predictions) use ($outcomes, &$count, &$skipped): void {
                foreach ($predictions as $prediction) {
                    if (! $this->shouldReconcile($prediction)) {
                        $skipped++;

                        continue;
                    }

                    $outcomes->reconcile($prediction);
                    $count++;
                }
            });

        $this->components->info("Reconciled {$count} raid prediction(s); skipped {$skipped} unchanged finalized prediction(s).");

        return self::SUCCESS;
    }

    private function shouldReconcile(RaidPrediction $prediction): bool
    {
        if (! $prediction->isTerminal() || $prediction->outcome_finalized_at === null) {
            return true;
        }

        $finalizedAt = $prediction->outcome_finalized_at;
        $graceSeconds = max(60, (int) config('raids.late_arrival_grace_seconds', 3600));
        if ($finalizedAt->greaterThan(CarbonImmutable::now()->subSeconds($graceSeconds))) {
            return true;
        }

        if ($prediction->outcomeAttacks()->where('updated_at', '>', $finalizedAt)->exists()) {
            return true;
        }

        return false;
    }
}
