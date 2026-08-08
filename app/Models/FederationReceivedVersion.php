<?php

namespace App\Models;

use App\Domain\Federation\Enums\ImportState;
use App\Domain\Federation\Enums\ReceivedDisposition;
use Database\Factories\FederationReceivedVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationReceivedVersion extends Model
{
    /** @use HasFactory<FederationReceivedVersionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_received_resource_id',
        'source_installation_id',
        'source_publication_id',
        'source_version_id',
        'version',
        'revision',
        'source_generation',
        'roster_revision',
        'schema_version',
        'canonical_payload',
        'payload_hash',
        'payload_bytes',
        'disposition',
        'import_state',
        'reviewed_by',
        'imported_operation_id',
        'import_baseline_generation',
        'missing_target_ids',
        'safe_error_code',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'imported_at',
        'payload_purged_at',
    ];

    protected $hidden = ['canonical_payload'];

    protected $attributes = [
        'disposition' => ReceivedDisposition::Pending->value,
        'import_state' => ImportState::NotRequested->value,
    ];

    protected function casts(): array
    {
        return [
            'canonical_payload' => 'encrypted',
            'disposition' => ReceivedDisposition::class,
            'import_state' => ImportState::class,
            'missing_target_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'imported_at' => 'datetime',
            'payload_purged_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(FederationReceivedResource::class, 'federation_received_resource_id');
    }

    public function importedOperation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'imported_operation_id');
    }
}
