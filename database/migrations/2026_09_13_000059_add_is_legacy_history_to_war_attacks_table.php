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
        WorldSchema::table('war_attacks', function (Blueprint $table) {
            $table->boolean('is_legacy_history')->default(false)->after('updated_at');
            $table->index(['is_legacy_history', 'date'], 'war_attacks_legacy_retention_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        WorldSchema::table('war_attacks', function (Blueprint $table) {
            $table->dropIndex('war_attacks_legacy_retention_index');
            $table->dropColumn('is_legacy_history');
        });
    }
};
