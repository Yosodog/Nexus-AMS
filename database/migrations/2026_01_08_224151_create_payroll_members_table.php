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
        Schema::create('payroll_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('payroll_grade_id')->constrained('payroll_grades');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('nation_id');
            $table->index(['payroll_grade_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_members');
    }
};
