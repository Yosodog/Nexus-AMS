<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        WorldSchema::table('raid_nation_observations', function (Blueprint $table): void {
            $table->index('observed_at', 'raid_nation_observations_observed_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        WorldSchema::table('raid_nation_observations', function (Blueprint $table): void {
            $table->dropIndex('raid_nation_observations_observed_at_index');
        });
    }
};
