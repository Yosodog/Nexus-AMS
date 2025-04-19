<?php

namespace App\Models;

use App\GraphQL\Models\War as WarGraphQL;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

class War extends Model
{
    protected $table = 'wars';
    protected $guarded = [];

    /**
     * @param WarGraphQL|array|stdClass $war
     * @return War
     */
    public static function updateFromAPI(WarGraphQL|array|stdClass $war): War
    {
        if ($war instanceof WarGraphQL || $war instanceof stdClass) {
            $war = (array)$war;
        }

        // Normalize if deprecated field is present
        if (isset($war['att_soldiers_killed'])) {
            $war = self::normalizeDeprecatedKilledFields($war);
        }

        $war['date'] = isset($war['date']) ? Carbon::parse($war['date'])->toDateTimeString() : null;
        $war['end_date'] = isset($war['end_date']) ? Carbon::parse($war['end_date'])->toDateTimeString() : null;

        return self::updateOrCreate(['id' => $war['id']], $war);
    }

    /**
     * Normalizes deprecated GraphQL subscription fields by converting *_killed → *_lost.
     * These deprecated fields are still included in subscriptions so have to do this
     *
     * @param array $war
     * @return array
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
            if (array_key_exists($killed, $war) && !array_key_exists($lost, $war)) {
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
     * @param $query
     * @return mixed
     */
    public function scopeActive($query)
    {
        return $query->whereNull('end_date');
    }
}
