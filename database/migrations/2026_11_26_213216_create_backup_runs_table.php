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
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20);
            $table->string('status', 20);
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('backup_path', 500)->nullable();
            $table->unsignedBigInteger('backup_size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique('pending_key', 'backup_runs_pending_unique');
            $table->index(['status', 'created_at'], 'backup_runs_status_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
