<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_inbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('message_id');
            $table->ulid('sender_installation_id');
            $table->ulid('recipient_installation_id');
            $table->ulid('sender_key_id');
            $table->ulid('recipient_key_id');
            $table->string('nonce', 64);
            $table->string('message_type', 64);
            $table->string('protocol_version', 16);
            $table->string('resource_schema', 64)->nullable();
            $table->char('payload_hash', 64);
            $table->longText('envelope_body')->nullable();
            $table->longText('decrypted_payload')->nullable();
            $table->string('status', 24)->default('accepted');
            $table->string('safe_error_code', 64)->nullable();
            $table->ulid('correlation_id');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();

            $table->unique(['sender_installation_id', 'message_id'], 'fed_inbox_sender_message_unique');
            $table->unique(
                ['sender_installation_id', 'sender_key_id', 'nonce'],
                'fed_inbox_sender_nonce_unique'
            );
            $table->index(['status', 'created_at'], 'fed_inbox_processing_idx');
            $table->index(['processed_at', 'created_at'], 'fed_inbox_retention_idx');
        });

        Schema::create('federation_outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('message_id');
            $table->foreignUlid('federation_link_id')->constrained('federation_links')->restrictOnDelete();
            $table->ulid('sender_installation_id');
            $table->ulid('recipient_installation_id');
            $table->ulid('sender_key_id');
            $table->ulid('recipient_key_id');
            $table->string('nonce', 64);
            $table->string('message_type', 64);
            $table->string('protocol_version', 16);
            $table->string('resource_schema', 64)->nullable();
            $table->longText('envelope_body')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('safe_error_code', 64)->nullable();
            $table->ulid('correlation_id');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('transport_accepted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['sender_installation_id', 'message_id'], 'fed_outbox_sender_message_unique');
            $table->unique(
                ['sender_installation_id', 'sender_key_id', 'nonce'],
                'fed_outbox_sender_nonce_unique'
            );
            $table->index(['status', 'next_attempt_at'], 'fed_outbox_due_idx');
            $table->index(['status', 'created_at'], 'fed_outbox_health_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_outbox_messages');
        Schema::dropIfExists('federation_inbox_messages');
    }
};
