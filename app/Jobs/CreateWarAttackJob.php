<?php

namespace App\Jobs;

use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\AllianceMembershipService;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateWarAttackJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable;

    public function __construct(public array $warAttacks) {}

    public function handle(): void
    {
        if (empty($this->warAttacks)) {
            return;
        }

        try {
            $membershipService = app(AllianceMembershipService::class);

            $nationIds = collect($this->warAttacks)
                ->flatMap(fn (array $attack) => [
                    $attack['att_id'] ?? null,
                    $attack['def_id'] ?? null,
                ])
                ->filter()
                ->unique()
                ->all();

            $alliancesByNation = Nation::query()
                ->whereIn('id', $nationIds)
                ->pluck('alliance_id', 'id');

            foreach ($this->warAttacks as $warAttack) {
                $attAlliance = $alliancesByNation[$warAttack['att_id']] ?? null;
                $defAlliance = $alliancesByNation[$warAttack['def_id']] ?? null;

                if (! $membershipService->contains($attAlliance) && ! $membershipService->contains($defAlliance)) {
                    continue;
                }

                WarAttack::storeFromEvent($warAttack);
            }

            WarAttack::pruneOlderThanDays(30);
        } catch (Exception $exception) {
            Log::error('Failed to create war attacks', [
                'error' => $exception->getMessage(),
                'trace_id' => Str::uuid()->toString(),
            ]);
        }
    }
}
