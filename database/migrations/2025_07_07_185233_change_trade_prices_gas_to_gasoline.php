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
        WorldSchema::table('trade_prices', function (Blueprint $table) {
            $table->renameColumn('gas', 'gasoline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        WorldSchema::table('trade_prices', function (Blueprint $table) {
            $table->renameColumn('gasoline', 'gas');
        });
    }
};
