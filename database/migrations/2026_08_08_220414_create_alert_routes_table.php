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
        Schema::create('alert_routes', function (Blueprint $table) {
            $table->id();
            WorldReference::alliance($table)->nullable()->nullOnDeleteInStandalone();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('event_key', 96);
            $table->string('minimum_severity', 16)->default('normal');
            $table->json('filter_config')->nullable();
            $table->json('delivery_policy');
            $table->boolean('is_active')->default(true);
            $table->string('active_fingerprint', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['event_key', 'is_active', 'alliance_id'], 'alert_route_event_scope_idx');
            $table->index(['alert_destination_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_routes');
    }
};
