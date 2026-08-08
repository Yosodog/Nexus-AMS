<?php

use App\Support\Database\WorldReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_enrollments', function (Blueprint $table) {
            $table->id();
            WorldReference::nation($table)->unique()->cascadeOnDeleteInStandalone();
            $table->unsignedBigInteger('account_id');
            $table->integer('previous_tax_id')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_enrollments');
    }
};
