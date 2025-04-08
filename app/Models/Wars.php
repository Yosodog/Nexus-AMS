<?php

namespace App\Models;

use App\GraphQL\Models\War;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Wars extends Model
{
    protected $table = 'wars';
    protected $guarded = [];

    public static function updateFromAPI(War|array|\stdClass $war): Wars
    {
        if ($war instanceof War || $war instanceof \stdClass) {
            $war = (array)$war;
        }

        $war['date'] = isset($war['date']) ? Carbon::parse($war['date'])->toDateTimeString() : null;
        $war['end_date'] = isset($war['end_date']) ? Carbon::parse($war['end_date'])->toDateTimeString() : null;

        return self::updateOrCreate(['id' => $war['id']], collect($war)->except(['__typename'])->toArray());
    }
}
