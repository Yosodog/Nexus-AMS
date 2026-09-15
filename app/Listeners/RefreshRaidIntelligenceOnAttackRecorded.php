<?php

namespace App\Listeners;

use App\Events\WarAttackRecorded;
use App\Jobs\RefreshRaidIntelligence;
use App\Models\RaidAttackObservation;
use App\Models\War;
use App\Models\WarAttack;
use App\Services\Economy\EconomyRules;
use App\Services\RaidIntelligenceDemand;
use App\Services\RuntimeCapabilities;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RefreshRaidIntelligenceOnAttackRecorded
{
    public function __construct(private RuntimeCapabilities $capabilities, private RaidIntelligenceDemand $demand) {}

    public function handle(WarAttackRecorded $event): void
    {
        if (! $this->capabilities->writesPublicWorld()) {
            return;
        }
        $attack = WarAttack::query()->find($event->attackId);
        $war = War::query()->find($event->warId);
        if ($attack === null || $war === null) {
            return;
        }
        $payload = Arr::only($attack->getAttributes(), [
            'id', 'date', 'att_id', 'def_id', 'type', 'victor', 'money_stolen',
            ...array_map(fn (string $resource): string => $resource.'_looted', EconomyRules::RESOURCE_KEYS),
        ]);
        $payload += [
            'war_id' => (int) $war->id, 'war_type' => $war->war_type,
            'original_attacker_id' => (int) $war->att_id, 'original_defender_id' => (int) $war->def_id,
            'att_alliance_id' => (int) $war->att_alliance_id, 'def_alliance_id' => (int) $war->def_alliance_id,
        ];
        RaidAttackObservation::query()->updateOrCreate(['id' => $attack->id], [
            'war_id' => $war->id, 'att_id' => $attack->att_id, 'def_id' => $attack->def_id,
            'occurred_at' => $attack->date, 'observed_at' => now(), 'payload' => $payload,
        ]);
        Cache::store(config('raids.intelligence_cache_store'))->forever('raid-intelligence:revision', (string) Str::uuid());
        $ids = [(int) $attack->att_id, (int) $attack->def_id];
        $this->demand->prioritize($ids);
        if (config('queue.default') !== 'sync') {
            RefreshRaidIntelligence::dispatch($ids)->afterCommit();
        }
    }
}
