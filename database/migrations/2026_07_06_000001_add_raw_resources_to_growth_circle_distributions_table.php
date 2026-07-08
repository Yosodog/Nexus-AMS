<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('growth_circle_distributions', function (Blueprint $table): void {
            $table->decimal('coal', 20, 2)->default(0);
            $table->decimal('oil', 20, 2)->default(0);
            $table->decimal('iron', 20, 2)->default(0);
            $table->decimal('bauxite', 20, 2)->default(0);
            $table->decimal('lead', 20, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('growth_circle_distributions', function (Blueprint $table): void {
            $table->dropColumn(['coal', 'oil', 'iron', 'bauxite', 'lead']);
        });
    }
};
