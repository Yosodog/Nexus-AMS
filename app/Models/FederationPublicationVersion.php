<?php

namespace App\Models;

use Database\Factories\FederationPublicationVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FederationPublicationVersion extends Model
{
    /** @use HasFactory<FederationPublicationVersionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_publication_id',
        'version',
        'revision',
        'source_generation',
        'schema_version',
        'recipients_hash',
        'preview_hash',
        'canonical_preview',
        'status',
        'created_by',
        'expires_at',
        'published_at',
    ];

    protected $hidden = ['canonical_preview'];

    protected $attributes = [
        'status' => 'preview',
    ];

    protected function casts(): array
    {
        return [
            'canonical_preview' => 'encrypted',
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(FederationPublication::class, 'federation_publication_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FederationPublicationDelivery::class);
    }
}
