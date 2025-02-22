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
        Schema::create('manual_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedInteger('admin_id');

            $table->decimal('money', 14, 2)->default(0);
            $table->decimal('coal', 14, 2)->default(0);
            $table->decimal('oil', 14, 2)->default(0);
            $table->decimal('uranium', 14, 2)->default(0);
            $table->decimal('lead', 14, 2)->default(0);
            $table->decimal('iron', 14, 2)->default(0);
            $table->decimal('bauxite', 14, 2)->default(0);
            $table->decimal('gasoline', 14, 2)->default(0);
            $table->decimal('munitions', 14, 2)->default(0);
            $table->decimal('steel', 14, 2)->default(0);
            $table->decimal('aluminum', 14, 2)->default(0);
            $table->decimal('food', 14, 2)->default(0);
            $table->string('note');
            $table->string('ip_address', 45);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_transactions');
    }
};
