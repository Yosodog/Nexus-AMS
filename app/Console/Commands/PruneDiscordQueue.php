<?php

namespace App\Console\Commands;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('discord-queue:prune {--pretend : Report matching rows without deleting them}')]
#[Description('Prune completed and failed Discord queue commands older than the configured retention')]
class PruneDiscordQueue extends Command
{
    /**
     * Execute the console command.
     *
     * Only terminal rows (complete/failed) are removed; pending and processing rows are never touched.
     */
    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $retentionDays = max(1, (int) config('nexus.discord_queue.retention_days', 90));
        $batchSize = max(1, (int) config('nexus.discord_queue.prune_batch_size', 500));

        $query = DiscordQueue::query()
            ->whereIn('status', [DiscordQueueStatus::Complete->value, DiscordQueueStatus::Failed->value])
            ->where('updated_at', '<', now()->subDays($retentionDays));

        $count = $pretend ? (clone $query)->count() : $this->prune($query, $batchSize);
        $action = $pretend ? 'would prune' : 'pruned';

        $this->components->info("{$count} Discord queue commands {$action}.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<DiscordQueue>  $query
     */
    private function prune(Builder $query, int $batchSize): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('updated_at')->limit($batchSize)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DiscordQueue::query()->whereKey($ids)->delete();
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
