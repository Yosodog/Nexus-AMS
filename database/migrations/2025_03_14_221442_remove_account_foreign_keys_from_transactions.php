<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['from_account_id']);
            $table->dropForeign(['to_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('from_account_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('no action');

            $table->foreign('to_account_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('no action');
        });
    }
};
