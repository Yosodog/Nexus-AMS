<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('mmr_assistant_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable(); // Not constrained to preserve logs

            $table->decimal('total_spent', 15, 2);

            foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource) {
                $table->decimal($resource, 15, 2)->default(0);
                $table->decimal("{$resource}_ppu", 10, 2)->nullable();
            }

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('mmr_assistant_purchases');
    }
};
