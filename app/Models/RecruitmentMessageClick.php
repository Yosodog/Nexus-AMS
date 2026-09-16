<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentMessageClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'recruitment_message_id',
        'cohort_key',
        'ip_hash',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentMessage, RecruitmentMessageClick>
     */
    public function recruitmentMessage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentMessage::class);
    }
}
