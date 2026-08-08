<?php

use App\Support\Database\WorldReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('member_inactivity_exceptions', function (Blueprint $table) {
            $table->id();
            WorldReference::nation($table, indexInHosted: false)->cascadeOnDeleteInStandalone();
            $table->string('category', 32);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->text('member_reason');
            $table->text('private_notes')->nullable();
            $table->json('affected_automations');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('last_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reviewed_at');
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['nation_id', 'starts_at', 'ends_at'], 'member_inactivity_exception_window_idx');
            $table->index(['expired_at', 'revoked_at', 'ends_at'], 'member_inactivity_exception_expiry_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_inactivity_exceptions');
    }
};
