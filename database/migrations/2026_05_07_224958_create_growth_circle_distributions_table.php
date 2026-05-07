<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->decimal('food', 20, 2)->default(0);
            $table->decimal('uranium', 20, 2)->default(0);
            $table->date('cycle_date');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('enrollment_id')
                ->references('id')
                ->on('growth_circle_enrollments')
                ->nullOnDelete();

            $table->unique(['nation_id', 'cycle_date'], 'gc_distribution_nation_cycle_unique');
            $table->index('cycle_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_distributions');
    }
};
