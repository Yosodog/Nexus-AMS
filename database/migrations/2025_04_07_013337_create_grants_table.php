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
        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description');
            $table->unsignedBigInteger('money')->default(0);
            $table->unsignedInteger('coal')->default(0);
            $table->unsignedInteger('oil')->default(0);
            $table->unsignedInteger('uranium')->default(0);
            $table->unsignedInteger('iron')->default(0);
            $table->unsignedInteger('bauxite')->default(0);
            $table->unsignedInteger('lead')->default(0);
            $table->unsignedInteger('gasoline')->default(0);
            $table->unsignedInteger('munitions')->default(0);
            $table->unsignedInteger('steel')->default(0);
            $table->unsignedInteger('aluminum')->default(0);
            $table->unsignedInteger('food')->default(0);

            $table->json('validation_rules')->nullable(); // For the future

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_one_time')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grants');
    }
};
