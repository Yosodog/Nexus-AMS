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
        Schema::create('alliance_finance_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('direction', 16);
            $table->string('category', 50);
            $table->string('description', 255)->nullable();

            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->nullableMorphs('source');

            $table->decimal('money', 20, 2)->default(0);
            $table->decimal('coal', 20, 3)->default(0);
            $table->decimal('oil', 20, 3)->default(0);
            $table->decimal('uranium', 20, 3)->default(0);
            $table->decimal('iron', 20, 3)->default(0);
            $table->decimal('bauxite', 20, 3)->default(0);
            $table->decimal('lead', 20, 3)->default(0);
            $table->decimal('gasoline', 20, 3)->default(0);
            $table->decimal('munitions', 20, 3)->default(0);
            $table->decimal('steel', 20, 3)->default(0);
            $table->decimal('aluminum', 20, 3)->default(0);
            $table->decimal('food', 20, 3)->default(0);

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('category');
            $table->index(['date', 'direction']);
            $table->index(['date', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alliance_finance_entries');
    }
};
