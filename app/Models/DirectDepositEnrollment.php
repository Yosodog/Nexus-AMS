<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectDepositEnrollment extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'offshore_id',
        'alliance_id',
        'account_id',
        'direct_deposit_tax_id',
        'fallback_tax_id',
        'previous_tax_id',
        'enrolled_at',
        'disenrollment_requested_at',
    ];

    /**
     * @var string[]
     */
    protected function casts(): array
    {
        return [
            'offshore_id' => 'integer',
            'alliance_id' => 'integer',
            'direct_deposit_tax_id' => 'integer',
            'fallback_tax_id' => 'integer',
            'previous_tax_id' => 'integer',
            'enrolled_at' => 'datetime',
            'disenrollment_requested_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function offshore(): BelongsTo
    {
        return $this->belongsTo(Offshore::class);
    }
}
