<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationKeyStatus;
use Database\Factories\FederationPeerKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationPeerKey extends Model
{
    /** @use HasFactory<FederationPeerKeyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_link_id',
        'remote_key_id',
        'generation',
        'status',
        'signing_public_key',
        'box_public_key',
        'signing_fingerprint',
        'box_fingerprint',
        'approved_at',
        'retired_at',
        'compromised_at',
    ];

    protected $attributes = [
        'status' => FederationKeyStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => FederationKeyStatus::class,
            'approved_at' => 'datetime',
            'retired_at' => 'datetime',
            'compromised_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }
}
