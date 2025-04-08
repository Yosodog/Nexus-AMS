<?php

namespace App\Models;

use App\GraphQL\Models\War;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Wars extends Model
{
    protected $table = 'wars';
    protected $guarded = [];

    public static function updateFromAPI(War $war): Wars
    {
        $date = isset($war->date) ? Carbon::parse($war->date)->toDateTimeString() : null;
        $endDate = isset($war->end_date) ? Carbon::parse($war->end_date)->toDateTimeString() : null;

        $data = collect((array) $war)
            ->except(['date', 'end_date', '__typename'])
            ->toArray();

        $data['date'] = $date;
        $data['end_date'] = $endDate;

        return self::updateOrCreate(['id' => $war->id], $data);
    }
}
