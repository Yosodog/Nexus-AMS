<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('mmr_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);

            // Percentages per resource (0.0 to 1.0)
            foreach (['coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food'] as $resource) {
                $table->decimal("{$resource}_pct", 5, 4)->default(0); // e.g. 0.125 = 12.5%
            }

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('mmr_configs');
    }
};
