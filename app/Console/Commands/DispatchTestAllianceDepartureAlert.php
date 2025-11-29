<?php

namespace App\Console\Commands;

use App\Enums\AlliancePositionEnum;
use App\Events\NationAllianceChanged;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class DispatchTestAllianceDepartureAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alliance-departure:test {nationId?} {--channel=} {--newAllianceId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a member leaving the alliance and enqueue a Discord departure alert for manual testing.';

    /**
     * Execute the console command.
     */
    public function handle(AllianceMembershipService $membershipService): int
    {
        $channel = $this->option('channel') ?: SettingService::getDiscordAllianceDepartureChannelId();

        if ($channel === '') {
            $this->error('No Discord channel configured. Pass --channel or set it in the settings page.');

            return self::FAILURE;
        }

        SettingService::setDiscordAllianceDepartureEnabled(true);
        SettingService::setDiscordAllianceDepartureChannelId($channel);

        $membershipIds = $membershipService->getAllianceIds();

        $nationId = $this->argument('nationId');

        $nation = Nation::query()
            ->when($nationId, fn ($query) => $query->whereKey((int) $nationId))
            ->when(! $nationId, function ($query) use ($membershipIds) {
                $query->whereIn('alliance_id', $membershipIds)
                    ->where(function ($q) {
                        $q->whereNull('alliance_position')
                            ->orWhere('alliance_position', '!=', AlliancePositionEnum::APPLICANT->value);
                    })
                    ->inRandomOrder();
            })
            ->first();

        if (! $nation) {
            $this->error('No eligible nation found. Provide a nationId or ensure membership nations exist.');

            return self::FAILURE;
        }

        $oldAllianceId = $nation->alliance_id;
        $oldPosition = $nation->alliance_position;

        if (! $membershipIds->contains((int) $oldAllianceId)) {
            $this->warn("Nation {$nation->id} is not in a configured alliance; listener will ignore.");
        }

        $newAllianceId = $this->option('newAllianceId');
        $newAllianceId = $newAllianceId !== null ? (int) $newAllianceId : null;

        $testNation = clone $nation;
        $testNation->alliance_id = $newAllianceId;
        $testNation->alliance_position = null;
        $testNation->setRelation('alliance', null);

        event(new NationAllianceChanged(
            nation: $testNation,
            oldAllianceId: $oldAllianceId,
            oldAlliancePosition: $oldPosition,
            newAllianceId: $newAllianceId,
            newAlliancePosition: null
        ));

        $this->info("Dispatched departure alert for nation {$nation->id} (old alliance {$oldAllianceId}, new alliance ".($newAllianceId ?? 'none').')');

        return self::SUCCESS;
    }
}
