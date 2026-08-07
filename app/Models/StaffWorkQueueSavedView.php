<?php

namespace App\Models;

use Database\Factories\StaffWorkQueueSavedViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffWorkQueueSavedView extends Model
{
    /** @use HasFactory<StaffWorkQueueSavedViewFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'public_id',
        'name',
        'filters',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
