<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_deposit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->unsignedBigInteger('account_id');
            $table->unsignedInteger('previous_tax_id');
            $table->timestamp('enrolled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_deposit_enrollments');
    }
};
