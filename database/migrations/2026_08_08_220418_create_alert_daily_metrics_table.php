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
        Schema::create('alert_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            WorldReference::alliance($table)->nullable()->nullOnDeleteInStandalone();
            $table->string('scope_key', 32)->default('global');
            $table->string('event_key', 96);
            $table->string('destination_kind', 32);
            $table->string('outcome', 32);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedInteger('latency_p50_ms')->nullable();
            $table->unsignedInteger('latency_p95_ms')->nullable();
            $table->unsignedInteger('latency_p99_ms')->nullable();
            $table->timestamps();

            $table->unique(
                ['metric_date', 'scope_key', 'event_key', 'destination_kind', 'outcome'],
                'alert_daily_metric_dimensions_unique',
            );
            $table->index(['metric_date', 'event_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_daily_metrics');
    }
};
