<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('remaining_balance', 15, 2);
            $table->date('next_due_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['primary_account_id']);
            $table->dropColumn(['primary_account_id', 'remaining_balance', 'next_due_date']);
        });
    }
};
