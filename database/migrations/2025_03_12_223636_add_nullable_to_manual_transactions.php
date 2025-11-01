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
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->nullable()->change();
            $table->string('ip_address', 45)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->nullable(false)->change();
            $table->string('ip_address', 45)->nullable(false)->change();
        });
    }
};
