<?php

namespace App\Models;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use Database\Factories\FederationCoalitionProposalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationCoalitionProposal extends Model
{
    /** @use HasFactory<FederationCoalitionProposalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'id',
        'federation_coalition_id',
        'proposer_installation_id',
        'proposal_type',
        'workflow_key',
        'target_installation_id',
        'requested_role',
        'base_roster_revision',
        'payload_hash',
        'canonical_payload',
        'status',
        'pending_key',
        'reviewed_by',
        'expires_at',
        'reviewed_at',
    ];

    protected $hidden = ['canonical_payload'];

    protected $attributes = [
        'status' => FederationWorkflowStatus::Pending->value,
        'pending_key' => 1,
    ];

    protected function casts(): array
    {
        return [
            'requested_role' => CoalitionRole::class,
            'canonical_payload' => 'encrypted',
            'status' => FederationWorkflowStatus::class,
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(FederationCoalition::class, 'federation_coalition_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
