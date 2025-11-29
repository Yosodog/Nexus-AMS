<?php

namespace App\Console\Commands;

use App\Events\WarDeclared;
use App\Listeners\CreateCounterOnWarDeclared;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchTestWarAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'war-alert:test {--channel=} {--warId=777}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate a war declaration and enqueue a Discord war alert for manual testing.';

    public function handle(
        AllianceMembershipService $membershipService,
        CreateCounterOnWarDeclared $listener
    ): int {
        $channel = $this->option('channel') ?: SettingService::getDiscordWarAlertChannelId();

        if (! $channel) {
            $this->error('No Discord channel configured. Set --channel or configure in the War Room settings.');

            return self::FAILURE;
        }

        SettingService::setDiscordWarAlertEnabled(true);
        SettingService::setDiscordWarAlertChannelId($channel);

        $friendlyAllianceIds = $membershipService->getAllianceIds();

        $defender = Nation::query()
            ->whereIn('alliance_id', $friendlyAllianceIds)
            ->inRandomOrder()
            ->first();

        if (! $defender) {
            $this->error('No defender nation found inside configured alliances.');

            return self::FAILURE;
        }

        $attacker = Nation::query()
            ->whereNotIn('alliance_id', $friendlyAllianceIds)
            ->inRandomOrder()
            ->first();

        if (! $attacker) {
            $this->error('No attacker nation found outside configured alliances.');

            return self::FAILURE;
        }

        $warId = (int) $this->option('warId') ?: 777;

        $listener->handle(new WarDeclared(
            warId: $warId,
            attackerNationId: $attacker->id,
            attackerAllianceId: $attacker->alliance_id,
            attackerAlliancePosition: $attacker->alliance_position,
            defenderNationId: $defender->id,
            defenderAllianceId: $defender->alliance_id,
            defenderAlliancePosition: $defender->alliance_position
        ));

        $this->info("Enqueued war alert for war {$warId} (attacker {$attacker->id} -> defender {$defender->id})");
        Log::info('Manual war alert test dispatched', [
            'war_id' => $warId,
            'attacker_nation_id' => $attacker->id,
            'defender_nation_id' => $defender->id,
        ]);

        return self::SUCCESS;
    }
}
