<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class WarAttack extends Model
{
    protected $table = 'war_attacks';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'date' => 'datetime',
        'improvements_destroyed' => 'array',
        'cities_infra_before' => 'array',
    ];

    private const COLUMN_WHITELIST = [
        'id',
        'date',
        'att_id',
        'def_id',
        'type',
        'war_id',
        'victor',
        'success',
        'attcas1',
        'defcas1',
        'attcas2',
        'defcas2',
        'city_id',
        'infra_destroyed',
        'improvements_lost',
        'money_stolen',
        'note',
        'city_infra_before',
        'infra_destroyed_value',
        'att_mun_used',
        'def_mun_used',
        'att_gas_used',
        'def_gas_used',
        'money_destroyed',
        'resistance_lost',
        'military_salvage_aluminum',
        'military_salvage_steel',
        'att_soldiers_used',
        'att_soldiers_lost',
        'def_soldiers_used',
        'def_soldiers_lost',
        'att_tanks_used',
        'att_tanks_lost',
        'def_tanks_used',
        'def_tanks_lost',
        'att_aircraft_used',
        'att_aircraft_lost',
        'def_aircraft_used',
        'def_aircraft_lost',
        'att_ships_used',
        'att_ships_lost',
        'def_ships_used',
        'def_ships_lost',
        'att_missiles_lost',
        'def_missiles_lost',
        'att_nukes_lost',
        'def_nukes_lost',
        'improvements_destroyed',
        'infra_destroyed_percentage',
        'cities_infra_before',
        'money_looted',
        'coal_looted',
        'oil_looted',
        'uranium_looted',
        'iron_looted',
        'bauxite_looted',
        'lead_looted',
        'gasoline_looted',
        'munitions_looted',
        'steel_looted',
        'aluminum_looted',
        'food_looted',
        'loot_info',
        'resistance_eliminated',
    ];

    public static function storeFromEvent(array $warAttack): ?self
    {
        if (! isset($warAttack['id'])) {
            return null;
        }

        $payload = self::normalisePayload($warAttack);

        return self::updateOrCreate(['id' => $payload['id']], Arr::except($payload, 'id'));
    }

    public static function pruneOlderThanDays(int $days): void
    {
        try {
            self::query()
                ->where('date', '<', now()->subDays($days))
                ->delete();
        } catch (Throwable $exception) {
            Log::warning('Failed pruning war attacks', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected static function normalisePayload(array $warAttack): array
    {
        $warAttack['date'] = isset($warAttack['date'])
            ? Carbon::parse($warAttack['date'])->toDateTimeString()
            : null;

        $warAttack['improvements_destroyed'] = $warAttack['improvements_destroyed'] ?? [];
        $warAttack['cities_infra_before'] = $warAttack['cities_infra_before'] ?? [];

        return Arr::only($warAttack, self::COLUMN_WHITELIST);
    }

    public function war(): BelongsTo
    {
        return $this->belongsTo(War::class);
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'att_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'def_id');
    }
}
