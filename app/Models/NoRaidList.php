<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoRaidList extends Model
{
    use HasFactory;

    protected $table = 'no_raid_list';

    protected $fillable = [
        'alliance_id',
    ];

    /**
     * @return BelongsTo
     */
    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class, "alliance_id");
    }
}
