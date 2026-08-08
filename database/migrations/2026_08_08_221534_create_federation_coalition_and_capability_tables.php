<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_coalitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->ulid('coordinator_installation_id');
            $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('roster_revision')->default(1);
            $table->char('roster_hash', 64);
            $table->longText('canonical_manifest');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('dissolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'fed_coalition_expiry_idx');
        });

        Schema::create('federation_coalition_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_coalition_id')->constrained('federation_coalitions')->cascadeOnDelete();
            $table->ulid('installation_id');
            $table->foreignUlid('federation_link_id')->nullable()->constrained('federation_links')->nullOnDelete();
            $table->string('role', 24);
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('roster_revision');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['federation_coalition_id', 'installation_id'],
                'fed_coalition_member_unique'
            );
            $table->index(['installation_id', 'status'], 'fed_coalition_member_status_idx');
        });

        Schema::create('federation_coalition_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_coalition_id')->constrained('federation_coalitions')->cascadeOnDelete();
            $table->foreignUlid('federation_link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->ulid('installation_id');
            $table->string('role', 24);
            $table->string('direction', 16);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->ulid('source_message_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['federation_coalition_id', 'installation_id', 'pending_key'],
                'fed_coalition_invite_pending_unique'
            );
            $table->index(['status', 'expires_at'], 'fed_coalition_invite_expiry_idx');
            $table->index('source_message_id', 'fed_coalition_invite_source_message_idx');
        });

        Schema::create('federation_coalition_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_coalition_id')->constrained('federation_coalitions')->cascadeOnDelete();
            $table->ulid('proposer_installation_id');
            $table->string('proposal_type', 48);
            $table->string('workflow_key', 96);
            $table->ulid('target_installation_id')->nullable();
            $table->string('requested_role', 24)->nullable();
            $table->unsignedBigInteger('base_roster_revision');
            $table->char('payload_hash', 64);
            $table->longText('canonical_payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['federation_coalition_id', 'workflow_key', 'pending_key'],
                'fed_coalition_proposal_pending_unique'
            );
            $table->index(['status', 'expires_at'], 'fed_coalition_proposal_expiry_idx');
        });

        Schema::create('federation_capabilities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('issuer_installation_id');
            $table->ulid('peer_installation_id');
            $table->foreignUlid('federation_coalition_id')->constrained('federation_coalitions')->cascadeOnDelete();
            $table->string('resource_type', 96);
            $table->string('direction', 16);
            $table->unsignedBigInteger('revision');
            $table->string('state', 24)->default('active');
            $table->boolean('is_local')->default(false);
            $table->char('statement_hash', 64);
            $table->longText('canonical_statement');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique([
                'issuer_installation_id',
                'peer_installation_id',
                'federation_coalition_id',
                'resource_type',
                'direction',
                'revision',
            ], 'fed_capability_revision_unique');
            $table->index([
                'issuer_installation_id',
                'peer_installation_id',
                'federation_coalition_id',
                'resource_type',
                'direction',
                'state',
            ], 'fed_capability_lookup_idx');
            $table->index(['state', 'expires_at'], 'fed_capability_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_capabilities');
        Schema::dropIfExists('federation_coalition_proposals');
        Schema::dropIfExists('federation_coalition_invitations');
        Schema::dropIfExists('federation_coalition_memberships');
        Schema::dropIfExists('federation_coalitions');
    }
};
