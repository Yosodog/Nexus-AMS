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
        Schema::create('audit_result_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('audit_result_id')->nullable();
            $table->foreignId('audit_rule_id')->nullable()->constrained('audit_rules')->nullOnDelete();
            $table->string('target_type', 32);
            $table->string('target_key', 191);
            WorldReference::nation($table)->nullable()->nullOnDeleteInStandalone();
            WorldReference::city($table)->nullable()->nullOnDeleteInStandalone();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['nation_id', 'occurred_at'], 'audit_events_nation_time_idx');
            $table->index(['audit_rule_id', 'event_type'], 'audit_events_rule_type_idx');
            $table->index('audit_result_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_result_events');
    }
};
