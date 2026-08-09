<?php

namespace App\Models;

use Database\Factories\OperationsSourceStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationsSourceState extends Model
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    /** @use HasFactory<OperationsSourceStateFactory> */
    use HasFactory;

    protected $fillable = [
        'source_type',
        'status',
        'generation_id',
        'item_count',
        'projected_at',
        'last_success_at',
        'last_failure_at',
        'stale_at',
        'error_code',
        'error_summary',
    ];

    protected $attributes = [
        'status' => self::STATUS_HEALTHY,
        'item_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'projected_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }
}
