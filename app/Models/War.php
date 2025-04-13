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

        $war['date'] = isset($war['date']) ? Carbon::parse($war['date'])->toDateTimeString() : null;
        $war['end_date'] = isset($war['end_date']) ? Carbon::parse($war['end_date'])->toDateTimeString() : null;

        return self::updateOrCreate(['id' => $war['id']], $war);
        //        return self::updateOrCreate(['id' => $war['id']], collect($war)->except(['__typename'])->toArray());
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
