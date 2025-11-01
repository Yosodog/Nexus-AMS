<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('money')->default(0);
            $table->unsignedBigInteger('coal')->default(0);
            $table->unsignedBigInteger('oil')->default(0);
            $table->unsignedBigInteger('uranium')->default(0);
            $table->unsignedBigInteger('iron')->default(0);
            $table->unsignedBigInteger('bauxite')->default(0);
            $table->unsignedBigInteger('lead')->default(0);
            $table->unsignedBigInteger('gasoline')->default(0);
            $table->unsignedBigInteger('munitions')->default(0);
            $table->unsignedBigInteger('steel')->default(0);
            $table->unsignedBigInteger('aluminum')->default(0);
            $table->unsignedBigInteger('food')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->dropColumn([
                'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead',
                'gasoline', 'munitions', 'steel', 'aluminum', 'food',
            ]);
        });
    }
};
