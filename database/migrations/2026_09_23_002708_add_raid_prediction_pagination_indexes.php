<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->index('declared_at', 'raid_predictions_declared_index');
            $table->index(['attacker_nation_id', 'declared_at'], 'raid_predictions_attacker_declared_index');
        });
    }

    public function down(): void
    {
        Schema::table('raid_predictions', function (Blueprint $table): void {
            $table->dropIndex('raid_predictions_declared_index');
            $table->dropIndex('raid_predictions_attacker_declared_index');
        });
    }
};
