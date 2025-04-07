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
        Schema::create('grant_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('nation_id');
            $table->unsignedInteger('account_id');

            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grant_applications');
    }
};
