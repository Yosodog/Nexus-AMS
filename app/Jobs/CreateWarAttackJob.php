<?php

namespace App\Jobs;

use App\Enums\WarAttackTypeEnum;
use App\Events\WarAttackRecorded;
use App\Models\Nation;
use App\Models\WarAttack;
use App\Services\AllianceMembershipService;
use App\Services\SubscriptionRecordQuarantine;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CreateWarAttackJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable;

    public $timeout = 20;

    public function __construct(public array $warAttacks) {}

    public function handle(SubscriptionRecordQuarantine $quarantine): void
    {
        if (empty($this->warAttacks)) {
            return;
        }

        try {
            $membershipService = app(AllianceMembershipService::class);
            $supportedAttacks = [];

            foreach ($this->warAttacks as $warAttack) {
                $type = is_array($warAttack) && isset($warAttack['type']) && is_string($warAttack['type'])
                    ? WarAttackTypeEnum::tryFrom($warAttack['type'])
                    : null;

                if ($type === null) {
                    $quarantine->quarantine(
                        'warattack',
                        'create',
                        $warAttack,
                        'unknown_war_attack_type'
                    );

                    continue;
                }

                $supportedAttacks[] = $warAttack;
            }

            $nationIds = collect($supportedAttacks)
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

            foreach ($supportedAttacks as $warAttack) {
                $attAlliance = $alliancesByNation[$warAttack['att_id']] ?? null;
                $defAlliance = $alliancesByNation[$warAttack['def_id']] ?? null;

                if (! $membershipService->contains($attAlliance) && ! $membershipService->contains($defAlliance)) {
                    continue;
                }

                $attack = WarAttack::storeFromEvent($warAttack);

                if ($attack !== null) {
                    event(new WarAttackRecorded((int) $attack->id, (int) $attack->war_id));
                }
            }

            WarAttack::pruneOlderThanDays(30);
        } catch (Throwable $exception) {
            Log::error('Failed to create war attacks', [
                'war_attack_ids' => collect($this->warAttacks)->pluck('id')->filter()->take(10)->values()->all(),
                'record_count' => count($this->warAttacks),
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
                'trace_id' => Str::uuid()->toString(),
            ]);

            throw $exception;
        }
    }
}
