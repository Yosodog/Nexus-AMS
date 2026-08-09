<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('milcom_operation_id')->constrained('milcom_operations')->restrictOnDelete();
            $table->foreignUlid('federation_coalition_id')->constrained('federation_coalitions')->restrictOnDelete();
            $table->ulid('source_installation_id');
            $table->string('resource_type', 96);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedBigInteger('current_revision')->default(0);
            $table->unsignedInteger('source_generation');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'fed_publication_expiry_idx');
            $table->index(['milcom_operation_id', 'status'], 'fed_publication_operation_idx');
            $table->index('source_installation_id', 'fed_publication_source_idx');
        });

        Schema::create('federation_publication_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_publication_id')
                ->constrained('federation_publications', indexName: 'fed_pub_version_publication_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('revision');
            $table->unsignedInteger('source_generation');
            $table->string('schema_version', 16);
            $table->char('recipients_hash', 64);
            $table->char('preview_hash', 64);
            $table->longText('canonical_preview')->nullable();
            $table->string('status', 24)->default('preview');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['federation_publication_id', 'version'], 'fed_publication_version_unique');
            $table->unique(['federation_publication_id', 'revision'], 'fed_publication_revision_unique');
            $table->index(['status', 'expires_at'], 'fed_publication_version_expiry_idx');
        });

        Schema::create('federation_publication_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_publication_version_id')
                ->constrained('federation_publication_versions', indexName: 'fed_pub_delivery_version_fk')
                ->cascadeOnDelete();
            $table->foreignUlid('federation_link_id')->constrained('federation_links')->restrictOnDelete();
            $table->ulid('recipient_installation_id');
            $table->string('state', 32)->default('pending');
            $table->longText('canonical_payload')->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('payload_bytes');
            $table->ulid('outbox_message_id')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('transport_accepted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('access_revocation_revision')->nullable();
            $table->timestamp('access_revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['federation_publication_version_id', 'recipient_installation_id'],
                'fed_publication_delivery_unique'
            );
            $table->index(['recipient_installation_id', 'state'], 'fed_delivery_recipient_state_idx');
        });

        Schema::create('federation_received_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_link_id')->nullable()->constrained('federation_links')->nullOnDelete();
            $table->ulid('source_installation_id');
            $table->ulid('source_publication_id');
            $table->ulid('coalition_id')->nullable();
            $table->string('resource_type', 96);
            $table->string('state', 32)->default('pending_review');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedBigInteger('current_revision')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_installation_id', 'source_publication_id'],
                'fed_received_resource_source_unique'
            );
            $table->index(['state', 'expires_at'], 'fed_received_resource_expiry_idx');
        });

        Schema::create('federation_received_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('federation_received_resource_id')
                ->constrained('federation_received_resources', indexName: 'fed_received_version_resource_fk')
                ->cascadeOnDelete();
            $table->ulid('source_installation_id');
            $table->ulid('source_publication_id');
            $table->ulid('source_version_id');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('revision');
            $table->unsignedInteger('source_generation');
            $table->unsignedBigInteger('roster_revision');
            $table->string('schema_version', 16);
            $table->longText('canonical_payload')->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('payload_bytes');
            $table->string('disposition', 24)->default('pending');
            $table->string('import_state', 32)->default('not_requested');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_operation_id')->nullable()->constrained('milcom_operations')->nullOnDelete();
            $table->unsignedInteger('import_baseline_generation')->nullable();
            $table->json('missing_target_ids')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamps();

            $table->unique([
                'source_installation_id',
                'source_publication_id',
                'source_version_id',
            ], 'fed_received_version_source_unique');
            $table->unique(
                ['federation_received_resource_id', 'version'],
                'fed_received_version_number_unique'
            );
            $table->index(['disposition', 'import_state'], 'fed_received_review_import_idx');
            $table->index(['imported_operation_id', 'import_state'], 'fed_received_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_received_versions');
        Schema::dropIfExists('federation_received_resources');
        Schema::dropIfExists('federation_publication_deliveries');
        Schema::dropIfExists('federation_publication_versions');
        Schema::dropIfExists('federation_publications');
    }
};
