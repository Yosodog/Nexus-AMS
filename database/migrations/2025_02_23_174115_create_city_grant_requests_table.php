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
        Schema::create('city_grant_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('city_number');
            $table->unsignedInteger('grant_amount');
            $table->unsignedInteger('nation_id');
            $table->unsignedInteger('account_id');
            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_grant_requests');
    }
};
