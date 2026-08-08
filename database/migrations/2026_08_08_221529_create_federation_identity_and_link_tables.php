<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedTinyInteger('singleton_key')->default(1)->unique();
            $table->string('origin', 512);
            $table->string('display_name');
            $table->unsignedBigInteger('ownership_epoch')->default(1);
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('federation_identity_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('federation_identities')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('active_key')->nullable();
            $table->string('signing_public_key', 128);
            $table->longText('signing_private_key');
            $table->string('box_public_key', 128);
            $table->longText('box_private_key');
            $table->string('signing_fingerprint', 128);
            $table->string('box_fingerprint', 128);
            $table->longText('rotation_statement')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retiring_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->unique(['identity_id', 'generation'], 'fed_identity_key_generation_unique');
            $table->unique(['identity_id', 'active_key'], 'fed_identity_active_key_unique');
            $table->index(['status', 'purge_after'], 'fed_identity_key_retention_idx');
        });

        Schema::create('federation_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('remote_installation_id')->unique();
            $table->string('remote_display_name')->nullable();
            $table->string('approved_origin', 512);
            $table->string('status', 24)->default('pending_remote');
            $table->unsignedBigInteger('remote_ownership_epoch')->default(1);
            $table->string('negotiated_protocol_version', 16)->nullable();
            $table->json('negotiated_resource_versions')->nullable();
            $table->string('suspension_reason_code', 64)->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_contact_at'], 'fed_link_health_idx');
        });

        Schema::create('federation_peer_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_link_id')->constrained('federation_links')->cascadeOnDelete();
            $table->ulid('remote_key_id');
            $table->unsignedInteger('generation');
            $table->string('status', 24)->default('pending');
            $table->string('signing_public_key', 128);
            $table->string('box_public_key', 128);
            $table->string('signing_fingerprint', 128);
            $table->string('box_fingerprint', 128);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->timestamps();

            $table->unique(['federation_link_id', 'remote_key_id'], 'fed_peer_remote_key_unique');
            $table->unique(['federation_link_id', 'generation'], 'fed_peer_generation_unique');
            $table->index(['federation_link_id', 'status'], 'fed_peer_key_status_idx');
        });

        Schema::create('federation_link_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_link_id')->nullable()->constrained('federation_links')->nullOnDelete();
            $table->string('direction', 16);
            $table->string('peer_origin', 512);
            $table->ulid('peer_installation_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->json('discovery_snapshot')->nullable();
            $table->ulid('source_message_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['peer_origin', 'pending_key'], 'fed_link_invite_pending_unique');
            $table->index(['status', 'expires_at'], 'fed_link_invite_expiry_idx');
            $table->index('source_message_id', 'fed_link_invite_source_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_link_invitations');
        Schema::dropIfExists('federation_peer_keys');
        Schema::dropIfExists('federation_links');
        Schema::dropIfExists('federation_identity_keys');
        Schema::dropIfExists('federation_identities');
    }
};
