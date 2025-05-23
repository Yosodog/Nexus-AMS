<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('direct_deposit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('bank_record_id');
            $table->decimal('money', 15, 2)->default(0);
            $table->decimal('coal', 15, 2)->default(0);
            $table->decimal('oil', 15, 2)->default(0);
            $table->decimal('uranium', 15, 2)->default(0);
            $table->decimal('iron', 15, 2)->default(0);
            $table->decimal('bauxite', 15, 2)->default(0);
            $table->decimal('lead', 15, 2)->default(0);
            $table->decimal('gasoline', 15, 2)->default(0);
            $table->decimal('munitions', 15, 2)->default(0);
            $table->decimal('steel', 15, 2)->default(0);
            $table->decimal('aluminum', 15, 2)->default(0);
            $table->decimal('food', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_deposit_logs');
    }
};