<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantEventType;
use Database\Factories\TenantEventReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TenantEventReceipt extends Model
{
    /** @use HasFactory<TenantEventReceiptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'delivery_id',
        'event_id',
        'contract_version',
        'event_type',
        'subject_key',
        'event_digest',
        'transport_nonce',
        'trace_id',
        'occurred_at',
        'published_at',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contract_version' => 'integer',
            'event_type' => TenantEventType::class,
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Tenant event receipts are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Tenant event receipts are immutable.');
        });
    }
}
