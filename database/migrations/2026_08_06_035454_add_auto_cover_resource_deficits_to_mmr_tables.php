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
        Schema::table('mmr_configs', function (Blueprint $table): void {
            $table->boolean('auto_cover_resource_deficits')
                ->default(false)
                ->after('enabled');
        });

        Schema::table('mmr_assistant_purchases', function (Blueprint $table): void {
            $table->string('allocation_mode', 20)
                ->default('manual')
                ->after('total_spent');
            $table->timestamp('projection_calculated_at')
                ->nullable()
                ->after('allocation_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mmr_assistant_purchases', function (Blueprint $table): void {
            $table->dropColumn(['allocation_mode', 'projection_calculated_at']);
        });

        Schema::table('mmr_configs', function (Blueprint $table): void {
            $table->dropColumn('auto_cover_resource_deficits');
        });
    }
};
