<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantCallbackStatus;
use App\Enums\TenantCallbackType;
use Database\Factories\TenantCallbackDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TenantCallbackDelivery extends Model
{
    /** @use HasFactory<TenantCallbackDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    private const IMMUTABLE_FIELDS = [
        'callback_id',
        'tenant_id',
        'event_type',
        'subject_key',
        'release_id',
        'payload',
        'occurred_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'callback_id',
        'tenant_id',
        'event_type',
        'subject_key',
        'release_id',
        'payload',
        'status',
        'attempt_count',
        'last_response_status',
        'last_failure_code',
        'next_attempt_at',
        'last_attempted_at',
        'occurred_at',
        'delivered_at',
    ];

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => TenantCallbackType::class,
            'payload' => 'array',
            'status' => TenantCallbackStatus::class,
            'attempt_count' => 'integer',
            'last_response_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $delivery): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($delivery->isDirty($field)) {
                    throw new LogicException("Tenant callback field [{$field}] is immutable.");
                }
            }
        });
    }
}
