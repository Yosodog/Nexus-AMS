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
        Schema::create('intel_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->nullable()->index();
            $table->string('nation_name');
            $table->foreignId('user_id')->nullable()->index();
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
            $table->decimal('operation_cost', 14, 2)->default(0);
            $table->unsignedInteger('spies_captured')->default(0);
            $table->boolean('was_detected')->default(false);
            $table->string('source')->default('web');
            $table->text('raw_text');
            $table->string('hash', 64)->index();
            $table->timestamps();

            $table->index('nation_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intel_reports');
    }
};
