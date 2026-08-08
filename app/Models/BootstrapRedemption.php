<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BootstrapRedemptionMode;
use App\Enums\TenantBootstrapAction;
use Database\Factories\BootstrapRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class BootstrapRedemption extends Model
{
    /** @use HasFactory<BootstrapRedemptionFactory> */
    use HasFactory;

    /** @var list<string> */
    private const ALWAYS_IMMUTABLE = [
        'token_hash',
        'tenant_id',
        'cloud_user_id',
        'action',
        'release_id',
        'alliance_id',
        'nation_id',
        'claims_digest',
        'issued_at',
        'expires_at',
    ];

    /** @var list<string> */
    private const FINAL_FIELDS = [
        'local_user_id',
        'mode',
        'redeemed_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        ...self::ALWAYS_IMMUTABLE,
        ...self::FINAL_FIELDS,
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
        'claims_digest',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => TenantBootstrapAction::class,
            'mode' => BootstrapRedemptionMode::class,
            'alliance_id' => 'integer',
            'nation_id' => 'integer',
            'local_user_id' => 'integer',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $redemption): void {
            foreach (self::ALWAYS_IMMUTABLE as $field) {
                if ($redemption->isDirty($field)) {
                    throw new LogicException("Bootstrap redemption field [{$field}] is immutable.");
                }
            }

            if ($redemption->getOriginal('redeemed_at') === null) {
                return;
            }

            foreach (self::FINAL_FIELDS as $field) {
                if ($redemption->isDirty($field)) {
                    throw new LogicException("Bootstrap redemption field [{$field}] is immutable after redemption.");
                }
            }
        });
    }
}
