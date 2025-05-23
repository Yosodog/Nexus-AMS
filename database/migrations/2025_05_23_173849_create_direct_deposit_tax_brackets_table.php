<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('direct_deposit_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('city_number')->unique();
            $table->decimal('money', 10, 2)->default(0);
            $table->decimal('coal', 10, 2)->default(0);
            $table->decimal('oil', 10, 2)->default(0);
            $table->decimal('uranium', 10, 2)->default(0);
            $table->decimal('iron', 10, 2)->default(0);
            $table->decimal('bauxite', 10, 2)->default(0);
            $table->decimal('lead', 10, 2)->default(0);
            $table->decimal('gasoline', 10, 2)->default(0);
            $table->decimal('munitions', 10, 2)->default(0);
            $table->decimal('steel', 10, 2)->default(0);
            $table->decimal('aluminum', 10, 2)->default(0);
            $table->decimal('food', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_deposit_tax_brackets');
    }
};
