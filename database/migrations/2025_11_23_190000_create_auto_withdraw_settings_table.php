<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_withdraw_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('resource', 50);
            $table->unsignedBigInteger('threshold');
            $table->unsignedBigInteger('withdraw_amount');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_withdraw_at')->nullable();
            $table->timestamps();

            $table->unique(['nation_id', 'resource']);
            $table->index('nation_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_withdraw_settings');
    }
};
