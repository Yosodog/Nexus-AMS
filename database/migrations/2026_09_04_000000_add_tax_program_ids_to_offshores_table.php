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
        Schema::table('offshores', function (Blueprint $table) {
            $table->unsignedInteger('direct_deposit_tax_id')->nullable()->after('mutation_key');
            $table->unsignedInteger('direct_deposit_fallback_tax_id')->nullable()->after('direct_deposit_tax_id');
            $table->unsignedInteger('growth_circles_tax_id')->nullable()->after('direct_deposit_fallback_tax_id');
            $table->unsignedInteger('growth_circles_fallback_tax_id')->nullable()->after('growth_circles_tax_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offshores', function (Blueprint $table) {
            $table->dropColumn([
                'direct_deposit_tax_id',
                'direct_deposit_fallback_tax_id',
                'growth_circles_tax_id',
                'growth_circles_fallback_tax_id',
            ]);
        });
    }
};
