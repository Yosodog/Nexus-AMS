<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            // Convert float to decimal
            $table->decimal('money', 20, 2)->default(0)->change();
            $table->decimal('coal', 20, 2)->default(0)->change();
            $table->decimal('oil', 20, 2)->default(0)->change();
            $table->decimal('uranium', 20, 2)->default(0)->change();
            $table->decimal('iron', 20, 2)->default(0)->change();
            $table->decimal('bauxite', 20, 2)->default(0)->change();
            $table->decimal('lead', 20, 2)->default(0)->change();
            $table->decimal('gasoline', 20, 2)->default(0)->change();
            $table->decimal('munitions', 20, 2)->default(0)->change();
            $table->decimal('steel', 20, 2)->default(0)->change();
            $table->decimal('aluminum', 20, 2)->default(0)->change();
            $table->decimal('food', 20, 2)->default(0)->change();

            // Add index on date
            $table->index('date');

            // Add generated column for day
            $table->date('day')->storedAs('DATE(`date`)');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            // Revert to float
            $table->float('money')->default(0)->change();
            $table->float('coal')->default(0)->change();
            $table->float('oil')->default(0)->change();
            $table->float('uranium')->default(0)->change();
            $table->float('iron')->default(0)->change();
            $table->float('bauxite')->default(0)->change();
            $table->float('lead')->default(0)->change();
            $table->float('gasoline')->default(0)->change();
            $table->float('munitions')->default(0)->change();
            $table->float('steel')->default(0)->change();
            $table->float('aluminum')->default(0)->change();
            $table->float('food')->default(0)->change();

            $table->dropIndex(['date']);
            $table->dropIndex(['day']);
            $table->dropColumn('day');
        });
    }
};
