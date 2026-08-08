<?php

namespace App\Models;

use Database\Factories\FederationIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FederationIdentity extends Model
{
    /** @use HasFactory<FederationIdentityFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'origin',
        'display_name',
        'ownership_epoch',
        'enabled',
        'enabled_at',
        'disabled_at',
    ];

    protected $attributes = [
        'singleton_key' => 1,
        'ownership_epoch' => 1,
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function keys(): HasMany
    {
        return $this->hasMany(FederationIdentityKey::class, 'identity_id');
    }

    public function activeKey(): HasOne
    {
        return $this->hasOne(FederationIdentityKey::class, 'identity_id')
            ->where('active_key', 1);
    }
}
