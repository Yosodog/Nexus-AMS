<?php

namespace App\Console\Commands;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Services\Discord\DiscordQueueLeaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverLegacyDiscordQueue extends Command
{
    protected $signature = 'discord-queue:recover-legacy
        {ids?* : Specific legacy processing queue IDs to inspect}
        {--requeue : Requeue the explicitly listed IDs instead of running a dry-run}';

    protected $description = 'Inspect or explicitly requeue legacy processing Discord commands without leases';

    public function handle(): int
    {
        /** @var array<int, string> $ids */
        $ids = $this->argument('ids');
        $shouldRequeue = (bool) $this->option('requeue');

        if ($shouldRequeue && $ids === []) {
            $this->error('The --requeue option requires one or more explicit queue IDs.');

            return self::INVALID;
        }

        $query = DiscordQueue::query()
            ->where('status', DiscordQueueStatus::Processing->value)
            ->whereNull('lease_token')
            ->orderBy('created_at');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $commands = $query->get();

        $this->table(
            ['ID', 'Action', 'Attempts', 'Created'],
            $commands->map(fn (DiscordQueue $command): array => [
                $command->id,
                $command->action,
                $command->attempts,
                optional($command->created_at)->toIso8601String(),
            ])->all(),
        );

        if (! $shouldRequeue) {
            $this->info("Dry run: {$commands->count()} legacy processing command(s) found. No rows changed.");

            return self::SUCCESS;
        }

        $foundIds = $commands->pluck('id')->all();
        $missingIds = array_values(array_diff($ids, $foundIds));

        if ($missingIds !== []) {
            $this->error('These IDs are not legacy processing commands: '.implode(', ', $missingIds));

            return self::FAILURE;
        }

        $exhaustedIds = $commands
            ->filter(fn (DiscordQueue $command): bool => $command->attempts >= DiscordQueueLeaseService::MAX_ATTEMPTS)
            ->pluck('id')
            ->all();

        if ($exhaustedIds !== []) {
            $this->error('These IDs have exhausted all attempts and cannot be requeued: '.implode(', ', $exhaustedIds));

            return self::FAILURE;
        }

        DB::transaction(function () use ($foundIds): void {
            DiscordQueue::query()
                ->whereIn('id', $foundIds)
                ->where('status', DiscordQueueStatus::Processing->value)
                ->whereNull('lease_token')
                ->lockForUpdate()
                ->get()
                ->each(function (DiscordQueue $command): void {
                    $command->forceFill([
                        'status' => DiscordQueueStatus::Pending,
                        'available_at' => Carbon::now(),
                        'claim_request_id' => null,
                        'worker_id' => null,
                        'leased_until' => null,
                        'last_error' => [
                            'code' => 'legacy_manual_requeue',
                            'message' => 'An operator explicitly requeued this legacy processing command.',
                        ],
                    ])->save();
                });
        }, attempts: 3);

        $this->info('Requeued '.count($foundIds).' explicitly selected legacy processing command(s).');

        return self::SUCCESS;
    }
}
