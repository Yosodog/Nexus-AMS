<?php

namespace App\Models;

use App\AutoSync\Concerns\AutoSyncsWithPoliticsAndWar;
use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\AutoSync\SyncDefinition;
use App\GraphQL\Models\War as WarGraphQL;
use App\Services\GraphQLQueryBuilder;
use App\Services\WarQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

class War extends Model implements SyncableWithPoliticsAndWar
{
    use AutoSyncsWithPoliticsAndWar;

    protected $table = 'wars';
    protected $guarded = [];

    /**
     * Upsert a war record from a Politics & War payload.
     *
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
     * Retrieve the attacking nation for the war.
     *
     * @return BelongsTo
     */
    public function attacker()
    {
        return $this->belongsTo(Nation::class, 'att_id');
    }

    /**
     * Retrieve the defending nation for the war.
     *
     * @return BelongsTo
     */
    public function defender()
    {
        return $this->belongsTo(Nation::class, 'def_id');
    }

    /**
     * Constrain the query to wars that are still active.
     *
     * @param $query
     * @return mixed
     */
    public function scopeActive($query): mixed
    {
        return $query->where(fn ($q) =>
        $q->whereNull('end_date')
            ->where('turns_left', '>', 0)
        );
    }

    /**
     * Describe how to synchronize wars from Politics & War.
     *
     * @return SyncDefinition
     */
    public static function getAutoSyncDefinition(): SyncDefinition
    {
        return new SyncDefinition(
            self::class,
            'id',
            function (array $ids) {
                $ids = array_values(array_unique(array_map('intval', $ids)));

                if (empty($ids)) {
                    return [];
                }

                $arguments = [
                    'id' => count($ids) === 1
                        ? $ids[0]
                        : GraphQLQueryBuilder::literal('[' . implode(', ', $ids) . ']'),
                ];

                return WarQueryService::getMultipleWars($arguments, max(1, min(count($ids), config('pw-sync.chunk_size', 100))), false, false);
            },
            function ($record) {
                return self::updateFromAPI($record);
            }
        );
    }
}
