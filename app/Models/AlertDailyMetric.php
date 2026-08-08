<?php

namespace App\Models;

use Database\Factories\AlertDailyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDailyMetric extends Model
{
    /** @use HasFactory<AlertDailyMetricFactory> */
    use HasFactory;

    protected $fillable = [
        'metric_date',
        'alliance_id',
        'scope_key',
        'event_key',
        'destination_kind',
        'outcome',
        'total',
        'latency_p50_ms',
        'latency_p95_ms',
        'latency_p99_ms',
    ];

    protected $attributes = [
        'scope_key' => 'global',
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'total' => 'integer',
            'latency_p50_ms' => 'integer',
            'latency_p95_ms' => 'integer',
            'latency_p99_ms' => 'integer',
        ];
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }
}
