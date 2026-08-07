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
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task_identifier');
            $table->char('task_mutex_hash', 64);
            $table->string('status', 24);
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->string('hostname', 191);
            $table->uuid('correlation_id')->unique();
            $table->string('exception_class')->nullable();
            $table->timestamps();

            $table->index(
                ['task_identifier', 'status', 'finished_at'],
                'task_runs_freshness_idx',
            );
            $table->index(
                ['task_mutex_hash', 'hostname', 'status', 'started_at'],
                'task_runs_active_idx',
            );
            $table->index(['status', 'created_at'], 'task_runs_retention_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
