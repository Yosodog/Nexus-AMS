<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecruitedNation extends Model
{
    protected $fillable = [
        'nation_id',
        'primary_sent_at',
        'follow_up_scheduled_for',
        'follow_up_sent_at',
    ];

    protected $casts = [
        'primary_sent_at' => 'datetime',
        'follow_up_scheduled_for' => 'datetime',
        'follow_up_sent_at' => 'datetime',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class);
    }
}
