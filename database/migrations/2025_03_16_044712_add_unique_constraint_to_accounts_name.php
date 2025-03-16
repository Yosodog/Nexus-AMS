<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add a generated column for active status and create a unique index on it
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('unique_name_key')->virtualAs(
                "IF(deleted_at IS NULL, CONCAT(name, '_', nation_id), NULL)"
            )->nullable();
            $table->unique('unique_name_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['unique_name_key']);
            $table->dropColumn('unique_name_key');
        });
    }
}; 