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
        Schema::create('audit_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_rule_id')->constrained('audit_rules')->cascadeOnDelete();
            $table->string('target_type');
            WorldReference::nation($table)->nullable()->nullOnDeleteInStandalone();
            WorldReference::city($table)->nullable()->nullOnDeleteInStandalone();
            $table->json('details')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_evaluated_at');
            $table->timestamps();

            $table->unique(['audit_rule_id', 'target_type', 'nation_id', 'city_id'], 'audit_results_unique_target');
            $table->index(['nation_id', 'city_id', 'target_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_results');
    }
};
