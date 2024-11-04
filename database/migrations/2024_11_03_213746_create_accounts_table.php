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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->nullable();
            $table->string('name');
            $table->decimal('money', 15, 2)->default(0);
            $table->decimal('coal', 15, 2)->default(0);
            $table->decimal('oil', 14, 2)->default(0);
            $table->decimal('uranium', 14, 2)->default(0);
            $table->decimal('iron', 14, 2)->default(0);
            $table->decimal('bauxite', 14, 2)->default(0);
            $table->decimal('lead', 14, 2)->default(0);
            $table->decimal('gasoline', 14, 2)->default(0);
            $table->decimal('munitions', 14, 2)->default(0);
            $table->decimal('steel', 14, 2)->default(0);
            $table->decimal('aluminum', 14, 2)->default(0);
            $table->decimal('food', 14, 2)->default(0);
            $table->boolean("frozen")->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign("nation_id")
                ->references("id")
                ->on("nations")
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
