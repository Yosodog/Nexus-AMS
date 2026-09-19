<?php

namespace App\Console\Commands;

use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Signature('raids:prune-intelligence {--pretend : Report matching observations without deleting them}')]
#[Description('Prune expired raid intelligence while retaining the latest nation observation')]
class PruneRaidIntelligence extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $batchSize = max(1, (int) config('raids.prune_batch_size', 1000));
        $nationCutoff = now()->subDays(max(1, (int) config('raids.checkpoint_retention_days', 31)));
        $attackRetentionDays = max(
            (int) config('raids.history_days', 30),
            (int) config('raids.attack_retention_days', 31),
        );

        $nationQuery = RaidNationObservation::query()
            ->whereNull('current_key')
            ->where('confirmed_through', '<', $nationCutoff);
        $attackQuery = RaidAttackObservation::query()
            ->where('occurred_at', '<', now()->subDays($attackRetentionDays));

        $nationCount = $this->prune($nationQuery, RaidNationObservation::class, $pretend, $batchSize);
        $attackCount = $this->prune($attackQuery, RaidAttackObservation::class, $pretend, $batchSize);
        $action = $pretend ? 'would prune' : 'pruned';

        $this->components->info(($nationCount + $attackCount)." raid intelligence observations {$action}.");
        $this->line("nation observations: {$nationCount}");
        $this->line("attack observations: {$attackCount}");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<Model>  $modelClass
     */
    private function prune(Builder $query, string $modelClass, bool $pretend, int $batchSize): int
    {
        if ($pretend) {
            return (clone $query)->count();
        }

        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit($batchSize)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += $modelClass::query()->whereKey($ids)->delete();
        } while ($ids->count() === $batchSize);

        return $deleted;
    }
}
