<?php

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
        Schema::create('operations_source_states', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 64);
            $table->string('status', 24)->default('healthy');
            $table->string('generation_id', 64)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('projected_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->unique('source_type', 'operations_source_type_unique');
            $table->index(['status', 'stale_at'], 'operations_source_status_stale_idx');
            $table->index(['last_success_at', 'source_type'], 'operations_source_success_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_source_states');
    }
};
