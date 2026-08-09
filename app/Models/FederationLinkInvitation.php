<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationWorkflowStatus;
use Database\Factories\FederationLinkInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationLinkInvitation extends Model
{
    /** @use HasFactory<FederationLinkInvitationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'id',
        'federation_link_id',
        'direction',
        'peer_origin',
        'peer_installation_id',
        'token_hash',
        'status',
        'pending_key',
        'discovery_snapshot',
        'source_message_id',
        'created_by',
        'reviewed_by',
        'expires_at',
        'reviewed_at',
        'consumed_at',
    ];

    protected $hidden = ['token_hash'];

    protected $attributes = [
        'status' => FederationWorkflowStatus::Pending->value,
        'pending_key' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => FederationWorkflowStatus::class,
            'discovery_snapshot' => 'array',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
