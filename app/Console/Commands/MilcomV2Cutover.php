<?php

namespace App\Console\Commands;

use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Models\WarCounter;
use App\Models\WarPlan;
use App\Services\Discord\DiscordQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MilcomV2Cutover extends Command
{
    protected $signature = 'milcom:v2-cutover {--dry-run : Report the rows that would be archived}';

    protected $description = 'Archive legacy Milcom operations and disable their active workflow state';

    public function __construct(private readonly DiscordQueueService $discordQueueService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $planCount = WarPlan::query()->whereIn('status', ['planning', 'active'])->count();
        $counterCount = WarCounter::query()->whereIn('status', WarCounter::openStatuses())->count();

        if ($this->option('dry-run')) {
            $this->components->info("Would archive {$planCount} legacy plan(s) and {$counterCount} legacy counter(s).");

            return self::SUCCESS;
        }

        if (! (bool) config('milcom.game_rules.contract_verified', false)) {
            $this->components->error(
                'Milcom v2 cutover is blocked until MILCOM_RULES_CONTRACT_VERIFIED is enabled after live rule verification.'
            );

            return self::FAILURE;
        }

        $queuedRooms = DB::transaction(function (): int {
            $archivedAt = now();

            WarPlan::query()
                ->whereIn('status', ['planning', 'active'])
                ->update([
                    'status' => 'archived',
                    'archived_at' => $archivedAt,
                    'updated_at' => $archivedAt,
                ]);

            $counters = WarCounter::query()
                ->whereIn('status', WarCounter::openStatuses())
                ->lockForUpdate()
                ->get();

            $queued = 0;

            foreach ($counters as $counter) {
                $counter->forceFill([
                    'status' => 'archived',
                    'active_key' => null,
                    'archived_at' => $archivedAt,
                ])->save();

                $channelId = trim((string) $counter->discord_channel_id);

                if ($channelId === '') {
                    continue;
                }

                $dedupeKey = "legacy-war-counter:{$counter->id}:archive:v2-cutover";

                $this->discordQueueService->enqueue(
                    action: DiscordQueueAction::WarRoomArchive,
                    payload: [
                        'discord_channel_id' => $channelId,
                        'source' => [
                            'type' => 'war_counter',
                            'id' => $counter->id,
                        ],
                        'archive' => [
                            'lock' => true,
                            'title_prefix' => '[Archived] ',
                        ],
                        'archived_at' => $archivedAt->toIso8601String(),
                    ],
                    lane: DiscordQueueLane::SideEffects,
                    dedupeKey: $dedupeKey,
                );

                $queued++;
            }

            return $queued;
        }, attempts: 5);

        $this->components->info(
            "Archived {$planCount} legacy plan(s), {$counterCount} legacy counter(s), and queued {$queuedRooms} room archive(s)."
        );

        return self::SUCCESS;
    }
}
