<?php

namespace App\Models;

use App\GraphQL\Models\War as WarGraphQL;
use App\Services\ApiDateNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use stdClass;

class War extends Model
{
    /** @var list<string> */
    private const PERSISTED_FIELDS = [
        'date',
        'end_date',
        'reason',
        'war_type',
        'ground_control',
        'air_superiority',
        'naval_blockade',
        'winner_id',
        'turns_left',
        'att_id',
        'att_alliance_id',
        'att_alliance_position',
        'def_id',
        'def_alliance_id',
        'def_alliance_position',
        'att_points',
        'def_points',
        'att_peace',
        'def_peace',
        'att_resistance',
        'def_resistance',
        'att_fortify',
        'def_fortify',
        'att_gas_used',
        'def_gas_used',
        'att_mun_used',
        'def_mun_used',
        'att_alum_used',
        'def_alum_used',
        'att_steel_used',
        'def_steel_used',
        'att_infra_destroyed',
        'def_infra_destroyed',
        'att_money_looted',
        'def_money_looted',
        'def_soldiers_lost',
        'att_soldiers_lost',
        'def_tanks_lost',
        'att_tanks_lost',
        'def_aircraft_lost',
        'att_aircraft_lost',
        'def_ships_lost',
        'att_ships_lost',
        'att_missiles_used',
        'def_missiles_used',
        'att_nukes_used',
        'def_nukes_used',
        'att_infra_destroyed_value',
        'def_infra_destroyed_value',
    ];

    protected $table = 'wars';

    protected $guarded = [];

    public static function updateFromAPI(WarGraphQL|array|stdClass $war): War
    {
        if ($war instanceof WarGraphQL || $war instanceof stdClass) {
            $war = (array) $war;
        }

        if (! array_key_exists('id', $war) || ! is_numeric($war['id']) || (int) $war['id'] < 1) {
            throw new InvalidArgumentException('A positive war ID is required.');
        }

        $warId = (int) $war['id'];
        unset($war['id']);

        if (isset($war['att_soldiers_killed'])) {
            $war = self::normalizeDeprecatedKilledFields($war);
        }

        if (array_key_exists('date', $war)) {
            $war['date'] = self::normalizeApiTimestamp($war['date']);
        }

        if (array_key_exists('end_date', $war)) {
            $war['end_date'] = self::normalizeApiTimestamp($war['end_date']);
        }

        return self::updateOrCreate(['id' => $warId], Arr::only($war, self::PERSISTED_FIELDS));
    }

    public static function normalizeApiTimestamp(?string $value): ?string
    {
        return ApiDateNormalizer::normalizeTimestamp($value);
    }

    /**
     * Normalizes deprecated GraphQL subscription fields by converting *_killed → *_lost.
     * These deprecated fields are still included in subscriptions so have to do this
     */
    public static function normalizeDeprecatedKilledFields(array $war): array
    {
        $killedToLostMap = [
            'att_soldiers_killed' => 'att_soldiers_lost',
            'def_soldiers_killed' => 'def_soldiers_lost',
            'att_tanks_killed' => 'att_tanks_lost',
            'def_tanks_killed' => 'def_tanks_lost',
            'att_aircraft_killed' => 'att_aircraft_lost',
            'def_aircraft_killed' => 'def_aircraft_lost',
            'att_ships_killed' => 'att_ships_lost',
            'def_ships_killed' => 'def_ships_lost',
        ];

        foreach ($killedToLostMap as $killed => $lost) {
            if (array_key_exists($killed, $war) && ! array_key_exists($lost, $war)) {
                $war[$lost] = $war[$killed];
            }
            unset($war[$killed]);
        }

        return $war;
    }

    /**
     * @return BelongsTo
     */
    public function attacker()
    {
        return $this->belongsTo(Nation::class, 'att_id');
    }

    /**
     * @return BelongsTo
     */
    public function defender()
    {
        return $this->belongsTo(Nation::class, 'def_id');
    }

    /**
     * @param  Builder<War>  $query
     * @return Builder<War>
     */
    public function scopeActive($query): mixed
    {
        return $query->where(fn ($q) => $q->whereNull('end_date')
            ->where('turns_left', '>', 0)
        );
    }

    /**
     * @param  Builder<War>  $query
     * @param  list<int>  $firstNationIds
     * @param  list<int>  $secondNationIds
     * @return Builder<War>
     */
    public function scopeBetweenNationSets(
        Builder $query,
        array $firstNationIds,
        array $secondNationIds,
    ): Builder {
        return $query->where(function (Builder $pairQuery) use ($firstNationIds, $secondNationIds): void {
            $pairQuery
                ->where(function (Builder $forward) use ($firstNationIds, $secondNationIds): void {
                    $forward
                        ->whereIn('att_id', $firstNationIds)
                        ->whereIn('def_id', $secondNationIds);
                })
                ->orWhere(function (Builder $reverse) use ($firstNationIds, $secondNationIds): void {
                    $reverse
                        ->whereIn('def_id', $firstNationIds)
                        ->whereIn('att_id', $secondNationIds);
                });
        });
    }
}
