<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->integer('previous_tax_id')->nullable();
            $table->boolean('suspended')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_enrollments');
    }
};
