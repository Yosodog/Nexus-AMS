<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationKeyStatus;
use Database\Factories\FederationIdentityKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationIdentityKey extends Model
{
    /** @use HasFactory<FederationIdentityKeyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'identity_id',
        'generation',
        'status',
        'active_key',
        'signing_public_key',
        'signing_private_key',
        'box_public_key',
        'box_private_key',
        'signing_fingerprint',
        'box_fingerprint',
        'rotation_statement',
        'activated_at',
        'retiring_at',
        'retired_at',
        'compromised_at',
        'purge_after',
    ];

    protected $hidden = [
        'signing_private_key',
        'box_private_key',
        'rotation_statement',
    ];

    protected $attributes = [
        'status' => FederationKeyStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => FederationKeyStatus::class,
            'signing_private_key' => 'encrypted',
            'box_private_key' => 'encrypted',
            'rotation_statement' => 'encrypted',
            'activated_at' => 'datetime',
            'retiring_at' => 'datetime',
            'retired_at' => 'datetime',
            'compromised_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(FederationIdentity::class, 'identity_id');
    }
}
